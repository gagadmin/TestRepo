<?php

namespace App\Services\Integrations;

use App\Models\ApiConfiguration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class IntegrationRequestFactory
{
    public function make(?ApiConfiguration $configuration): PendingRequest
    {
        $request = Http::acceptJson()
            ->withoutRedirecting()
            ->timeout($configuration?->timeout_seconds ?? 30)
            ->retry($configuration?->retry_count ?? 0, 200, throw: false);

        if ($configuration?->encrypted_headers) {
            $request->withHeaders($this->safeHeaders($configuration->encrypted_headers));
        }

        $credentials = $configuration?->encrypted_credentials ?? [];

        return match ($configuration?->auth_type ?? 'none') {
            'bearer' => $request->withToken($credentials['token'] ?? ''),
            'api_key' => $request->withHeaders([
                $this->assertSafeHeaderName($credentials['header'] ?? 'X-API-Key') => $credentials['api_key'] ?? '',
            ]),
            'basic' => $request->withBasicAuth(
                $credentials['username'] ?? '',
                $credentials['password'] ?? ''
            ),
            default => $request,
        };
    }

    private function safeHeaders(array $headers): array
    {
        foreach ($headers as $name => $value) {
            $this->assertSafeHeaderName((string) $name);

            if (! is_string($value) || preg_match('/[\r\n]/', $value)) {
                throw new InvalidArgumentException('Integration header values must be single-line strings.');
            }
        }

        return $headers;
    }

    private function assertSafeHeaderName(string $name): string
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/', $name)
            || in_array(strtolower($name), [
                'host', 'content-length', 'transfer-encoding', 'connection',
                'forwarded', 'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto',
            ], true)) {
            throw new InvalidArgumentException('The configured integration header name is not allowed.');
        }

        return $name;
    }
}
