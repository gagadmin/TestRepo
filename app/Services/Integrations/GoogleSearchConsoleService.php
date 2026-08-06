<?php

namespace App\Services\Integrations;

use App\Data\ConnectionResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;
use Throwable;

class GoogleSearchConsoleService
{
    private const SITES_URL = 'https://www.googleapis.com/webmasters/v3/sites';

    private const OAUTH_SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function testConnection(?string $configuredSiteUrl = null): ConnectionResult
    {
        $startedAt = hrtime(true);

        try {
            $siteUrl = $this->siteUrl($configuredSiteUrl);
            $credentials = $this->credentials();
            $accessToken = $this->accessToken($credentials);
            $response = Http::acceptJson()
                ->withToken($accessToken)
                ->timeout($this->timeout())
                ->connectTimeout(min($this->timeout(), 10))
                ->get(self::SITES_URL);

            if (! $response->successful()) {
                return $this->failedResponse(
                    'api_error',
                    'Google Search Console rejected the site-list request.',
                    $response,
                    $startedAt,
                );
            }

            $sites = $response->json('siteEntry', []);

            if (! is_array($sites)) {
                throw new RuntimeException('Google Search Console returned an invalid site list.');
            }

            $accessible = collect($sites)->contains(
                fn (mixed $site): bool => is_array($site)
                    && hash_equals($siteUrl, (string) ($site['siteUrl'] ?? ''))
            );

            if (! $accessible) {
                return $this->failed(
                    'site_not_accessible',
                    'Authentication succeeded, but the configured Search Console property is not accessible to this service account.',
                    $startedAt,
                    200,
                    ['accessible_site_count' => count($sites)],
                );
            }

            return new ConnectionResult(
                successful: true,
                message: 'Google Search Console connection established and the configured property is accessible.',
                httpStatus: 200,
                durationMs: $this->duration($startedAt),
                context: [
                    'site_url' => $siteUrl,
                    'accessible_site_count' => count($sites),
                    'permission_level' => $this->permissionLevel($sites, $siteUrl),
                ],
            );
        } catch (ConnectionException) {
            return $this->failed(
                'connection_failed',
                'Google Search Console could not be reached.',
                $startedAt,
            );
        } catch (JsonException|RuntimeException $exception) {
            return $this->failed(
                'configuration_error',
                $exception->getMessage(),
                $startedAt,
            );
        } catch (Throwable) {
            return $this->failed(
                'unexpected_error',
                'The Google Search Console connection test failed unexpectedly.',
                $startedAt,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{rows: array<int, array<string, float|int|string>>, summary: array<string, float|int|string>}
     */
    public function analytics(array $query = [], ?string $configuredSiteUrl = null): array
    {
        try {
            $siteUrl = $this->siteUrl($configuredSiteUrl);
            [$dateFrom, $dateTo] = $this->dateRange($query);
            $dimension = $this->dimension($query['dimension'] ?? null);
            $limit = max(1, min((int) ($query['limit'] ?? 200), 200));
            $accessToken = $this->accessToken($this->credentials());
            $url = self::SITES_URL.'/'.rawurlencode($siteUrl).'/searchAnalytics/query';

            $totalsResponse = $this->analyticsRequest($accessToken, $url, [
                'startDate' => $dateFrom,
                'endDate' => $dateTo,
                'type' => 'web',
                'rowLimit' => 1,
            ]);
            $rowsResponse = $this->analyticsRequest($accessToken, $url, [
                'startDate' => $dateFrom,
                'endDate' => $dateTo,
                'dimensions' => [$dimension],
                'type' => 'web',
                'rowLimit' => $limit,
            ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Google Search Console could not be reached.', previous: $exception);
        }

        $rows = collect($rowsResponse->json('rows', []))
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): array => [
                $dimension => (string) data_get($row, 'keys.0', ''),
                'clicks' => (int) round((float) ($row['clicks'] ?? 0)),
                'impressions' => (int) round((float) ($row['impressions'] ?? 0)),
                'ctr' => round((float) ($row['ctr'] ?? 0) * 100, 2),
                'position' => round((float) ($row['position'] ?? 0), 2),
            ])
            ->values()
            ->all();
        $totals = $totalsResponse->json('rows.0', []);
        $totals = is_array($totals) ? $totals : [];

        return [
            'rows' => $rows,
            'summary' => [
                'site_url' => $siteUrl,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'dimension' => $dimension,
                'clicks' => (int) round((float) ($totals['clicks'] ?? 0)),
                'impressions' => (int) round((float) ($totals['impressions'] ?? 0)),
                'ctr' => round((float) ($totals['ctr'] ?? 0) * 100, 2),
                'position' => round((float) ($totals['position'] ?? 0), 2),
                'row_count' => count($rows),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function credentials(): array
    {
        $configuredPath = trim((string) config('services.search_console.credentials'));

        if ($configuredPath === '') {
            throw new RuntimeException('GOOGLE_APPLICATION_CREDENTIALS is not configured.');
        }

        $path = $this->absolutePath($configuredPath);

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The Google service-account credential file is missing or unreadable.');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('The Google service-account credential file could not be read.');
        }

        $credentials = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($credentials)
            || ($credentials['type'] ?? null) !== 'service_account'
            || ! is_string($credentials['client_email'] ?? null)
            || ! is_string($credentials['private_key'] ?? null)) {
            throw new RuntimeException('The Google credential file is not a valid service-account key.');
        }

        return $credentials;
    }

    private function siteUrl(?string $configuredSiteUrl): string
    {
        $siteUrl = trim($configuredSiteUrl ?? (string) config('services.search_console.site_url'));

        if ($siteUrl === '') {
            throw new RuntimeException('GOOGLE_SEARCH_CONSOLE_SITE_URL is not configured.');
        }

        if (! str_starts_with($siteUrl, 'sc-domain:')
            && filter_var($siteUrl, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('The Search Console property must be a URL-prefix or sc-domain property.');
        }

        return $siteUrl;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{string, string}
     */
    private function dateRange(array $query): array
    {
        $defaultEnd = now('America/Los_Angeles')->subDay()->toDateString();
        $dateTo = (string) ($query['date_to'] ?? $defaultEnd);
        $dateFrom = (string) ($query['date_from'] ?? now('America/Los_Angeles')->subDays(28)->toDateString());

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)
            || $dateFrom > $dateTo) {
            throw new RuntimeException('The Search Console date range is invalid.');
        }

        return [$dateFrom, $dateTo];
    }

    private function dimension(mixed $dimension): string
    {
        $dimension = is_string($dimension) && $dimension !== '' ? $dimension : 'query';

        if (! in_array($dimension, ['query', 'page', 'country', 'device', 'date'], true)) {
            throw new RuntimeException('The requested Search Console dimension is not supported.');
        }

        return $dimension;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function analyticsRequest(string $accessToken, string $url, array $payload): Response
    {
        $response = Http::acceptJson()
            ->withToken($accessToken)
            ->timeout($this->timeout())
            ->connectTimeout(min($this->timeout(), 10))
            ->post($url, $payload);

        if (! $response->successful()) {
            $reason = $response->json('error.errors.0.reason')
                ?? $response->json('error.details.0.reason')
                ?? $response->json('error.status');
            $suffix = is_string($reason) ? " ({$reason})" : '';

            throw new RuntimeException(
                "Google Search Console rejected the analytics request with HTTP {$response->status()}{$suffix}."
            );
        }

        if (! is_array($response->json())) {
            throw new RuntimeException('Google Search Console returned an invalid analytics response.');
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function accessToken(array $credentials): string
    {
        $issuedAt = time();
        $assertion = $this->encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ]).'.'.$this->encode([
            'iss' => $credentials['client_email'],
            'scope' => self::OAUTH_SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $issuedAt,
            'exp' => $issuedAt + 3600,
        ]);

        if (! openssl_sign($assertion, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The Google service-account private key could not sign the authentication request.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout($this->timeout())
            ->connectTimeout(min($this->timeout(), 10))
            ->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion.'.'.$this->base64UrlEncode($signature),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Google rejected the service-account authentication request.');
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Google did not return an access token.');
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function absolutePath(string $path): string
    {
        if (preg_match('/^(?:[A-Za-z]:[\\\\\\/]|[\\\\\\/]{2}|\\/)/', $path) === 1) {
            return $path;
        }

        return base_path(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));
    }

    private function timeout(): int
    {
        return max(1, min((int) config('services.search_console.timeout_seconds', 15), 60));
    }

    /**
     * @param  array<int, mixed>  $sites
     */
    private function permissionLevel(array $sites, string $siteUrl): ?string
    {
        foreach ($sites as $site) {
            if (is_array($site) && ($site['siteUrl'] ?? null) === $siteUrl) {
                $level = $site['permissionLevel'] ?? null;

                return is_string($level) ? $level : null;
            }
        }

        return null;
    }

    private function failedResponse(
        string $code,
        string $message,
        Response $response,
        int $startedAt,
    ): ConnectionResult {
        $googleStatus = $response->json('error.status');
        $googleReason = $response->json('error.errors.0.reason')
            ?? $response->json('error.details.0.reason');

        return $this->failed(
            $code,
            $message,
            $startedAt,
            $response->status(),
            array_filter([
                'google_status' => is_string($googleStatus) ? $googleStatus : null,
                'google_reason' => is_string($googleReason) ? $googleReason : null,
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function failed(
        string $code,
        string $message,
        int $startedAt,
        ?int $httpStatus = null,
        array $context = [],
    ): ConnectionResult {
        return new ConnectionResult(
            successful: false,
            message: $message,
            httpStatus: $httpStatus,
            errorCode: $code,
            durationMs: $this->duration($startedAt),
            context: $context,
        );
    }

    private function duration(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
