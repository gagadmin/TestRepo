<?php

namespace App\Services\Ai\Providers;

use App\Contracts\AiProvider;
use App\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OpenAiResponsesProvider implements AiProvider
{
    public function name(): string
    {
        return 'openai';
    }

    public function configured(): bool
    {
        return filled(config('ai.providers.openai.api_key'));
    }

    public function respond(array $payload): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('The OpenAI provider is not configured.');
        }

        $headers = array_filter([
            'OpenAI-Organization' => config('ai.providers.openai.organization'),
            'OpenAI-Project' => config('ai.providers.openai.project'),
        ]);

        $response = $this->request($headers, $payload);

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('The OpenAI service returned an invalid response.');
        }

        return $data;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $payload
     */
    private function request(array $headers, array $payload): Response
    {
        $maxRetries = max(0, min((int) config('ai.provider_retry_attempts', 2), 5));

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = Http::withToken(config('ai.providers.openai.api_key'))
                    ->acceptJson()
                    ->withHeaders($headers)
                    ->timeout(120)
                    ->post(config('ai.providers.openai.responses_url'), $payload);
            } catch (ConnectionException $exception) {
                if ($attempt === $maxRetries) {
                    throw new RuntimeException('The OpenAI service could not be reached.', previous: $exception);
                }

                $this->pauseBeforeRetry($attempt);

                continue;
            }

            if ($response->successful()) {
                return $response;
            }

            $failure = $this->failure($response);

            if (! $failure->retryable || $attempt === $maxRetries) {
                return $response;
            }

            $this->pauseBeforeRetry($attempt, $response);
        }

        throw new RuntimeException('The OpenAI service could not be reached.');
    }

    private function failure(Response $response): AiProviderException
    {
        $providerCode = $response->json('error.code');
        $providerType = $response->json('error.type');
        $code = is_string($providerCode) && $providerCode !== ''
            ? $providerCode
            : (is_string($providerType) ? $providerType : 'provider_error');

        if ($response->status() === 429 && $code === 'insufficient_quota') {
            return new AiProviderException(
                'OpenAI API quota is unavailable. Add billing credits or increase the project usage limit, then try again.',
                'insufficient_quota',
                429,
            );
        }

        if ($response->status() === 429) {
            return new AiProviderException(
                'OpenAI is temporarily rate-limiting requests. Please try again shortly.',
                'rate_limit_exceeded',
                429,
                true,
            );
        }

        if ($response->status() === 401) {
            return new AiProviderException(
                'OpenAI rejected the configured API key.',
                'authentication_error',
                401,
            );
        }

        if ($response->status() === 403) {
            return new AiProviderException(
                'The configured OpenAI project is not permitted to use this model or endpoint.',
                'permission_denied',
                403,
            );
        }

        if ($response->status() === 404) {
            return new AiProviderException(
                'The configured OpenAI model or Responses API endpoint is unavailable.',
                'resource_not_found',
                404,
            );
        }

        if ($response->status() >= 500) {
            return new AiProviderException(
                'OpenAI is temporarily unavailable. Please try again shortly.',
                'provider_unavailable',
                $response->status(),
                true,
            );
        }

        // Surface the provider's own reason (e.g. an unsupported parameter) so a
        // 400 is diagnosable instead of an opaque status code.
        $detail = $response->json('error.message');
        $suffix = is_string($detail) && $detail !== '' ? ' '.Str::limit($detail, 300) : '';

        return new AiProviderException(
            "The OpenAI request was rejected with status {$response->status()}.{$suffix}",
            $code,
            $response->status(),
        );
    }

    private function pauseBeforeRetry(int $attempt, ?Response $response = null): void
    {
        $retryAfter = $response?->header('Retry-After');
        $delayMs = is_numeric($retryAfter)
            ? (int) round((float) $retryAfter * 1000)
            : (int) config('ai.provider_retry_base_delay_ms', 500) * (2 ** $attempt);
        $delayMs = max(0, min($delayMs, 5000));

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }
}
