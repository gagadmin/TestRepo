<?php

namespace Tests\Feature;

use App\Exceptions\AiProviderException;
use App\Services\Ai\ProviderManager;
use App\Services\Ai\Providers\AzureOpenAiResponsesProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Azure OpenAI Responses provider.
 *
 * The behaviour under test is the failure vocabulary. `AiConversationController`
 * branches on AiProviderException to return a provider code, a retryable flag,
 * and a status the interface can act on; anything that escapes as a bare
 * RuntimeException is reported to the user as an indistinguishable 422. Each
 * status below therefore asserts the code and the retryable flag, not just that
 * something was thrown.
 */
class AzureAiProviderTest extends TestCase
{
    private const URL = 'https://contoso.openai.azure.com/openai/responses?api-version=2025-01-01';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai.providers.azure.api_key', 'azure-test-key');
        Config::set('ai.providers.azure.responses_url', self::URL);
        // Keep the retry pauses out of the suite's wall clock.
        Config::set('ai.provider_retry_base_delay_ms', 0);
    }

    /* ------------------------------------------------------------------
     | Configuration and wiring
     |------------------------------------------------------------------ */

    public function test_the_manager_selects_azure_when_it_is_configured_as_the_provider(): void
    {
        Config::set('ai.provider', 'azure');

        $this->assertInstanceOf(
            AzureOpenAiResponsesProvider::class,
            app(ProviderManager::class)->current()
        );
    }

    public function test_it_is_unconfigured_without_both_a_key_and_a_deployment_url(): void
    {
        $provider = app(AzureOpenAiResponsesProvider::class);
        $this->assertTrue($provider->configured());

        Config::set('ai.providers.azure.responses_url', null);
        $this->assertFalse($provider->configured());

        Config::set('ai.providers.azure.responses_url', self::URL);
        Config::set('ai.providers.azure.api_key', null);
        $this->assertFalse($provider->configured());
    }

    public function test_an_unconfigured_provider_refuses_before_calling_out(): void
    {
        Config::set('ai.providers.azure.api_key', null);
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The Azure OpenAI provider is not configured.');

        try {
            $this->respond();
        } finally {
            Http::assertNothingSent();
        }
    }

    /* ------------------------------------------------------------------
     | Success
     |------------------------------------------------------------------ */

    public function test_it_authenticates_with_the_api_key_header_and_returns_the_payload(): void
    {
        // Azure authenticates with `api-key`, not a bearer token.
        Http::fake(fn () => Http::response(['id' => 'resp_1', 'output' => []]));

        $data = $this->respond(['model' => 'gpt-4o', 'input' => 'hello']);

        $this->assertSame('resp_1', $data['id']);
        Http::assertSent(fn (Request $request) => $request->url() === self::URL
            && $request->hasHeader('api-key', 'azure-test-key')
            && ! $request->hasHeader('Authorization')
            && $request['model'] === 'gpt-4o');
    }

    public function test_a_non_array_body_is_rejected(): void
    {
        Http::fake(fn () => Http::response('"just a string"', 200, ['Content-Type' => 'application/json']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The Azure OpenAI service returned an invalid response.');

        $this->respond();
    }

    /* ------------------------------------------------------------------
     | Failure vocabulary
     |------------------------------------------------------------------ */

    public function test_a_rejected_key_is_reported_as_an_authentication_error(): void
    {
        Http::fake(fn () => Http::response(['error' => ['code' => 'invalid_api_key']], 401));

        $failure = $this->failure();

        $this->assertSame('authentication_error', $failure->providerCode);
        $this->assertFalse($failure->retryable);
        $this->assertSame(503, $failure->clientStatus());
    }

    public function test_a_forbidden_deployment_is_reported_as_permission_denied(): void
    {
        Http::fake(fn () => Http::response(['error' => ['code' => 'forbidden']], 403));

        $this->assertSame('permission_denied', $this->failure()->providerCode);
    }

    public function test_a_missing_deployment_is_reported_as_not_found(): void
    {
        Http::fake(fn () => Http::response(['error' => ['code' => 'DeploymentNotFound']], 404));

        $failure = $this->failure();

        $this->assertSame('resource_not_found', $failure->providerCode);
        $this->assertStringContainsString('deployment or api-version', $failure->getMessage());
    }

    public function test_rate_limiting_is_reported_as_retryable(): void
    {
        Http::fake(fn () => Http::response(['error' => ['code' => 'rate_limit']], 429));

        $failure = $this->failure();

        $this->assertSame('rate_limit_exceeded', $failure->providerCode);
        $this->assertTrue($failure->retryable);
        $this->assertSame(429, $failure->clientStatus());
    }

    public function test_exhausted_quota_is_separated_from_ordinary_rate_limiting(): void
    {
        // Waiting helps a rate limit; it does not help an exhausted quota, so
        // the two must not carry the same advice.
        Http::fake(fn () => Http::response(['error' => ['code' => 'insufficient_quota']], 429));

        $failure = $this->failure();

        $this->assertSame('insufficient_quota', $failure->providerCode);
        $this->assertFalse($failure->retryable);
    }

    public function test_an_outage_is_reported_as_retryable(): void
    {
        Http::fake(fn () => Http::response([], 503));

        $failure = $this->failure();

        $this->assertSame('provider_unavailable', $failure->providerCode);
        $this->assertTrue($failure->retryable);
    }

    public function test_a_rejected_request_surfaces_the_providers_own_reason(): void
    {
        // A 400 with no detail is undiagnosable; the content filter reports
        // itself this way.
        Http::fake(fn () => Http::response([
            'error' => ['code' => 'content_filter', 'message' => 'The response was filtered.'],
        ], 400));

        $failure = $this->failure();

        $this->assertSame('content_filter', $failure->providerCode);
        $this->assertStringContainsString('The response was filtered.', $failure->getMessage());
    }

    /* ------------------------------------------------------------------
     | Retry policy
     |------------------------------------------------------------------ */

    public function test_a_rejected_key_is_never_retried(): void
    {
        // Repeating an authentication failure wastes the caller's time and can
        // itself trip Azure rate limiting.
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response(['error' => ['code' => 'invalid_api_key']], 401);
        });

        $this->failure();

        $this->assertSame(1, $calls);
    }

    public function test_a_transient_failure_is_retried_up_to_the_configured_limit(): void
    {
        Config::set('ai.provider_retry_attempts', 2);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response([], 503);
        });

        $this->failure();

        $this->assertSame(3, $calls, 'One initial attempt plus two configured retries.');
    }

    public function test_the_configured_retry_limit_is_honoured(): void
    {
        // The setting is documented in .env.example and honoured by the other
        // providers; Azure previously hardcoded its own count and ignored it.
        Config::set('ai.provider_retry_attempts', 0);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response([], 503);
        });

        $this->failure();

        $this->assertSame(1, $calls);
    }

    public function test_a_retry_succeeds_when_the_service_recovers(): void
    {
        Config::set('ai.provider_retry_attempts', 2);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response([], 503)
                : Http::response(['id' => 'resp_recovered']);
        });

        $this->assertSame('resp_recovered', $this->respond()['id']);
        $this->assertSame(2, $calls);
    }

    /* ------------------------------------------------------------------
     | Helpers
     |------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function respond(array $payload = ['input' => 'hello']): array
    {
        return app(AzureOpenAiResponsesProvider::class)->respond($payload);
    }

    private function failure(): AiProviderException
    {
        try {
            $this->respond();
        } catch (AiProviderException $exception) {
            return $exception;
        }

        $this->fail('The provider did not raise an AiProviderException.');
    }
}
