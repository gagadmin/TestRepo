<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Guarded client for a configured web search provider.
 *
 * This is the one outbound path in the system whose target is administrator
 * configuration rather than a DataSource (see ADR-002). The provider settings
 * — endpoint, allowed hosts, API key — are supplied per call by the caller
 * (built from the AI tool row), never by the model. The safeguards that make
 * that acceptable all live here and must not be relaxed independently:
 *
 *   - the endpoint comes from configuration, never from the model;
 *   - the resolved host must be on the caller's allow-list;
 *   - IntegrationUrlGuard still vets scheme/host/IP/DNS;
 *   - requests do not follow redirects, are timeout- and retry-bounded, and
 *     oversized responses are rejected before parsing;
 *   - the API key is presented but never logged.
 *
 * DRAFT skeleton: normalizeResults() and the request parameter names are
 * written against a generic provider shape. Confirm the real provider's
 * contract and adjust before use.
 */
class WebSearchConnector
{
    public function __construct(
        private readonly IntegrationUrlGuard $urlGuard,
    ) {}

    /**
     * Run a search and return normalized, size-capped results.
     *
     * @param  array<string, mixed>  $config  Provider settings from the tool row.
     * @return array{results: array<int, array{title: string, url: string, snippet: string}>, provider_host: string}
     */
    public function search(array $config, string $query, int $limit): array
    {
        $query = trim($query);

        if ($query === '') {
            throw new InvalidArgumentException('The search query must not be empty.');
        }

        $endpoint = (string) ($config['endpoint'] ?? '');

        if ($endpoint === '') {
            throw new RuntimeException('The web search endpoint is not configured.');
        }

        // Same gate every integration URL passes: HTTPS, no embedded creds,
        // public IP, resolvable host.
        $this->urlGuard->assertAllowed($endpoint);
        $host = $this->assertAllowedHost($endpoint, (array) ($config['allowed_hosts'] ?? []));

        $maxResults = (int) ($config['max_results'] ?? 5);
        $limit = max(1, min($limit, $maxResults));

        $response = $this->request($config)->get($endpoint, [
            // Parameter names are provider-specific; adjust to match the
            // chosen provider's contract.
            'q' => $query,
            'count' => $limit,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("The web search provider returned HTTP {$response->status()}.");
        }

        $body = $response->body();
        $limitBytes = (int) ($config['response_limit_bytes'] ?? 1_000_000);

        if (strlen($body) > $limitBytes) {
            throw new RuntimeException('The web search response exceeded the configured safety limit.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('The web search provider did not return a JSON object.');
        }

        return [
            'results' => $this->normalizeResults($payload, $limit),
            'provider_host' => $host,
        ];
    }

    /**
     * Build the outbound request with the same hardening the integration layer
     * applies (see IntegrationRequestFactory): JSON, no redirects, bounded
     * timeout and retries.
     *
     * @param  array<string, mixed>  $config
     */
    private function request(array $config): PendingRequest
    {
        $request = Http::acceptJson()
            ->withoutRedirecting()
            ->timeout((int) ($config['timeout_seconds'] ?? 15))
            ->retry((int) ($config['retry_attempts'] ?? 1), 200, throw: false);

        $key = (string) ($config['api_key'] ?? '');

        return match ($config['auth_scheme'] ?? 'bearer') {
            'header' => $request->withHeaders([
                (string) ($config['key_header'] ?? 'X-API-Key') => $key,
            ]),
            default => $request->withToken($key),
        };
    }

    /**
     * The resolved host must be explicitly allow-listed. This is what keeps a
     * single configured provider from drifting into general outbound HTTP.
     *
     * @param  array<int, string>  $allowedHosts
     */
    private function assertAllowedHost(string $endpoint, array $allowedHosts): string
    {
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        $allowed = array_map('strtolower', array_map('strval', $allowedHosts));

        if ($host === '' || ! in_array($host, $allowed, true)) {
            throw new RuntimeException('The web search endpoint host is not on the allow-list.');
        }

        return $host;
    }

    /**
     * Map the provider payload into a small, uniform result set.
     *
     * Kept deliberately minimal: title, absolute URL, and a short snippet.
     * Everything is treated as untrusted text; the URL is what the assistant
     * cites. Adjust the paths below to the real provider response.
     *
     * @return array<int, array{title: string, url: string, snippet: string}>
     */
    private function normalizeResults(array $payload, int $limit): array
    {
        $rows = $payload['results'] ?? $payload['web']['results'] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->take($limit)
            ->map(fn ($row) => is_array($row) ? [
                'title' => (string) ($row['title'] ?? ''),
                'url' => (string) ($row['url'] ?? $row['link'] ?? ''),
                'snippet' => Str::limit((string) ($row['snippet'] ?? $row['description'] ?? ''), 400),
            ] : null)
            ->filter(fn ($row) => $row !== null && $row['url'] !== '')
            ->values()
            ->all();
    }
}
