<?php

namespace App\Services\Integrations;

use App\Contracts\DataConnector;
use App\Data\ConnectionResult;
use App\Models\DataSource;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

class HttpApiConnector implements DataConnector
{
    public function __construct(
        private readonly IntegrationUrlGuard $urlGuard,
        private readonly IntegrationRequestFactory $requests,
    ) {}

    public function testConnection(DataSource $dataSource): ConnectionResult
    {
        $configuration = $dataSource->apiConfiguration;
        $healthPath = $dataSource->settings['health_path'] ?? '/';
        $url = rtrim((string) $dataSource->base_url, '/').'/'.ltrim($healthPath, '/');
        $startedAt = hrtime(true);

        try {
            $this->urlGuard->assertAllowed($url);

            $response = $this->requests->make($configuration)
                ->get($url);

            $duration = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            return new ConnectionResult(
                successful: $response->successful(),
                message: $response->successful()
                    ? 'Connection established successfully.'
                    : 'The endpoint responded with an unsuccessful status.',
                httpStatus: $response->status(),
                errorCode: $response->successful() ? null : 'http_error',
                durationMs: $duration,
                context: ['endpoint' => $this->sanitizedEndpoint($url)],
            );
        } catch (ConnectionException $exception) {
            return $this->failedResult('connection_failed', 'The endpoint could not be reached.', $startedAt);
        } catch (Throwable $exception) {
            return $this->failedResult('configuration_error', $exception->getMessage(), $startedAt);
        }
    }

    private function failedResult(string $code, string $message, int $startedAt): ConnectionResult
    {
        return new ConnectionResult(
            successful: false,
            message: $message,
            errorCode: $code,
            durationMs: (int) round((hrtime(true) - $startedAt) / 1_000_000),
        );
    }

    private function sanitizedEndpoint(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').($parts['path'] ?? '/');
    }
}
