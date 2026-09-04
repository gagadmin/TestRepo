<?php

namespace App\Services\Ai\Providers;

use App\Contracts\AiProvider;
use App\Exceptions\AiProviderException;
use App\Services\Ai\Providers\Concerns\RetriesProviderRequests;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Azure OpenAI Responses API provider.
 *
 * Deliberately mirrors OpenAiResponsesProvider: the deployment URL carries the
 * Azure resource, deployment name, and api-version, and authentication uses the
 * `api-key` header rather than a bearer token, but the failure vocabulary and
 * retry policy are the same on purpose. `AiConversationController` branches on
 * AiProviderException to return a provider code, a retryable flag, and an
 * appropriate status; a provider that throws only bare RuntimeException
 * collapses every outcome — a rate limit, a rejected key, an outage — into one
 * indistinguishable 422, which is what this provider used to do.
 */
class AzureOpenAiResponsesProvider implements AiProvider
{
    use RetriesProviderRequests;

    public function name(): string
    {
        return 'azure';
    }

    public function configured(): bool
    {
        return filled(config('ai.providers.azure.api_key'))
            && filled(config('ai.providers.azure.responses_url'));
    }

    public function respond(array $payload): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('The Azure OpenAI provider is not configured.');
        }

        $response = $this->requestWithRetries(fn () => Http::withHeaders([
            'api-key' => config('ai.providers.azure.api_key'),
        ])
            ->acceptJson()
            ->timeout(120)
            ->post(config('ai.providers.azure.responses_url'), $payload));

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('The Azure OpenAI service returned an invalid response.');
        }

        return $data;
    }

    protected function unreachableMessage(): string
    {
        return 'The Azure OpenAI service could not be reached.';
    }

    protected function failure(Response $response): AiProviderException
    {
        $providerCode = $response->json('error.code');
        $providerType = $response->json('error.type');
        $code = is_string($providerCode) && $providerCode !== ''
            ? $providerCode
            : (is_string($providerType) ? $providerType : 'provider_error');

        if ($response->status() === 429 && $code === 'insufficient_quota') {
            return new AiProviderException(
                'The Azure OpenAI deployment has no quota available. Raise the deployment quota or wait for the current window to reset, then try again.',
                'insufficient_quota',
                429,
            );
        }

        if ($response->status() === 429) {
            return new AiProviderException(
                'Azure OpenAI is temporarily rate-limiting requests. Please try again shortly.',
                'rate_limit_exceeded',
                429,
                true,
            );
        }

        if ($response->status() === 401) {
            return new AiProviderException(
                'Azure OpenAI rejected the configured API key.',
                'authentication_error',
                401,
            );
        }

        if ($response->status() === 403) {
            return new AiProviderException(
                'The configured Azure OpenAI resource is not permitted to use this deployment.',
                'permission_denied',
                403,
            );
        }

        if ($response->status() === 404) {
            return new AiProviderException(
                'The configured Azure OpenAI deployment or api-version is unavailable.',
                'resource_not_found',
                404,
            );
        }

        if ($response->status() >= 500) {
            return new AiProviderException(
                'Azure OpenAI is temporarily unavailable. Please try again shortly.',
                'provider_unavailable',
                $response->status(),
                true,
            );
        }

        // Surface the provider's own reason so a 400 is diagnosable rather than
        // an opaque status code. Azure's content filter reports itself here.
        $detail = $response->json('error.message');
        $suffix = is_string($detail) && $detail !== '' ? ' '.Str::limit($detail, 300) : '';

        return new AiProviderException(
            "The Azure OpenAI request was rejected with status {$response->status()}.{$suffix}",
            $code,
            $response->status(),
        );
    }
}
