<?php

namespace Tests\Feature;

use App\Exceptions\AiProviderException;
use App\Services\Ai\Providers\GoogleAiStudioProvider;
use App\Services\Ai\Providers\OpenAiResponsesProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Failure vocabulary of the two live AI providers.
 *
 * `AiConversationController` turns an AiProviderException into a provider code,
 * a retryable flag, and a client status; everything else becomes an
 * undifferentiated 422. Azure's mapping is covered by AzureAiProviderTest —
 * these are the providers actually selected in deployment, and only the quota
 * and rate-limit paths were previously exercised, through the chat endpoint.
 *
 * They are tested at the provider rather than through the endpoint so each
 * status maps to one assertion, and so the shared retry behaviour is pinned
 * before the three providers' duplicated retry code is consolidated.
 */
class AiProviderFailureTest extends TestCase
{
    private const OPENAI_URL = 'https://api.openai.com/v1/responses';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai.providers.openai.api_key', 'openai-test-key');
        Config::set('ai.providers.openai.responses_url', self::OPENAI_URL);
        Config::set('ai.providers.google.api_key', 'google-test-key');
        Config::set('ai.providers.google.base_url', 'https://generativelanguage.googleapis.com/v1beta');
        Config::set('ai.provider_retry_base_delay_ms', 0);
        Config::set('ai.provider_retry_attempts', 0);
    }

    /* ------------------------------------------------------------------
     | OpenAI
     |------------------------------------------------------------------ */

    /**
     * @return array<string, array{0: int, 1: array<string, mixed>, 2: string, 3: bool, 4: int}>
     */
    public static function openAiFailures(): array
    {
        return [
            // status, body, expected code, retryable, client status
            'rejected key' => [401, ['error' => ['code' => 'invalid_api_key']], 'authentication_error', false, 503],
            'forbidden project' => [403, ['error' => ['code' => 'forbidden']], 'permission_denied', false, 503],
            'unknown model' => [404, ['error' => ['code' => 'model_not_found']], 'resource_not_found', false, 502],
            'rate limited' => [429, ['error' => ['code' => 'rate_limit_exceeded']], 'rate_limit_exceeded', true, 429],
            'exhausted quota' => [429, ['error' => ['code' => 'insufficient_quota']], 'insufficient_quota', false, 503],
            'server error' => [500, [], 'provider_unavailable', true, 502],
            'gateway error' => [503, [], 'provider_unavailable', true, 502],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('openAiFailures')]
    public function test_openai_maps_each_failure_to_an_actionable_code(
        int $status,
        array $body,
        string $expectedCode,
        bool $retryable,
        int $clientStatus,
    ): void {
        Http::fake(fn () => Http::response($body, $status));

        $failure = $this->failureFrom(fn () => app(OpenAiResponsesProvider::class)->respond(['input' => 'hello']));

        $this->assertSame($expectedCode, $failure->providerCode);
        $this->assertSame($retryable, $failure->retryable);
        $this->assertSame($clientStatus, $failure->clientStatus());
    }

    public function test_openai_surfaces_the_providers_own_reason_on_a_rejected_request(): void
    {
        // A bare "status 400" is undiagnosable; the unsupported parameter is
        // the only thing that tells the caller what to change.
        Http::fake(fn () => Http::response([
            'error' => ['code' => 'unsupported_parameter', 'message' => 'Unknown parameter: temperature.'],
        ], 400));

        $failure = $this->failureFrom(fn () => app(OpenAiResponsesProvider::class)->respond(['input' => 'hello']));

        $this->assertSame('unsupported_parameter', $failure->providerCode);
        $this->assertStringContainsString('Unknown parameter: temperature.', $failure->getMessage());
    }

    public function test_openai_falls_back_to_the_error_type_when_no_code_is_supplied(): void
    {
        Http::fake(fn () => Http::response(['error' => ['type' => 'invalid_request_error']], 400));

        $this->assertSame(
            'invalid_request_error',
            $this->failureFrom(fn () => app(OpenAiResponsesProvider::class)->respond(['input' => 'hi']))->providerCode
        );
    }

    public function test_openai_does_not_retry_a_rejected_key(): void
    {
        Config::set('ai.provider_retry_attempts', 3);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response(['error' => ['code' => 'invalid_api_key']], 401);
        });

        $this->failureFrom(fn () => app(OpenAiResponsesProvider::class)->respond(['input' => 'hello']));

        $this->assertSame(1, $calls);
    }

    public function test_openai_retries_an_outage_up_to_the_configured_limit(): void
    {
        Config::set('ai.provider_retry_attempts', 2);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response([], 503);
        });

        $this->failureFrom(fn () => app(OpenAiResponsesProvider::class)->respond(['input' => 'hello']));

        $this->assertSame(3, $calls, 'One initial attempt plus two configured retries.');
    }

    public function test_openai_rejects_a_non_array_body(): void
    {
        Http::fake(fn () => Http::response('"nope"', 200, ['Content-Type' => 'application/json']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The OpenAI service returned an invalid response.');

        app(OpenAiResponsesProvider::class)->respond(['input' => 'hello']);
    }

    /* ------------------------------------------------------------------
     | Google AI Studio
     |------------------------------------------------------------------ */

    /**
     * @return array<string, array{0: int, 1: string, 2: bool}>
     */
    public static function googleFailures(): array
    {
        return [
            'rejected key' => [401, 'authentication_error', false],
            'forbidden key' => [403, 'permission_denied', false],
            'unknown model' => [404, 'resource_not_found', false],
            'server error' => [500, 'provider_unavailable', true],
            'gateway error' => [503, 'provider_unavailable', true],
        ];
    }

    #[DataProvider('googleFailures')]
    public function test_google_maps_each_failure_to_an_actionable_code(
        int $status,
        string $expectedCode,
        bool $retryable,
    ): void {
        Http::fake(fn () => Http::response(['error' => ['status' => 'FAILED']], $status));

        $failure = $this->failureFrom(fn () => $this->respondWithGoogle());

        $this->assertSame($expectedCode, $failure->providerCode);
        $this->assertSame($retryable, $failure->retryable);
    }

    public function test_google_treats_a_429_as_retryable_rate_limiting(): void
    {
        /*
         * Documenting a deliberate divergence rather than asserting an ideal.
         * The Gemini API returns 429 for both temporary rate limiting and an
         * exhausted quota, and does not reliably separate them, so this
         * provider folds the two together and marks them retryable - where
         * OpenAI and Azure distinguish `insufficient_quota` and mark it
         * non-retryable. Retrying a rate limit succeeds; retrying an exhausted
         * quota fails harmlessly, so the forgiving reading is the safe one. If
         * this ever changes, this test should be the thing that notices.
         */
        Http::fake(fn () => Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429));

        $failure = $this->failureFrom(fn () => $this->respondWithGoogle());

        $this->assertSame('rate_limit_exceeded', $failure->providerCode);
        $this->assertTrue($failure->retryable);
        $this->assertStringContainsString('quota', $failure->getMessage());
    }

    public function test_google_uses_the_reported_status_as_the_code_for_an_unmapped_failure(): void
    {
        Http::fake(fn () => Http::response(['error' => ['status' => 'INVALID_ARGUMENT']], 400));

        $this->assertSame('invalid_argument', $this->failureFrom(fn () => $this->respondWithGoogle())->providerCode);
    }

    public function test_google_does_not_retry_a_rejected_key(): void
    {
        Config::set('ai.provider_retry_attempts', 3);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response(['error' => ['status' => 'UNAUTHENTICATED']], 401);
        });

        $this->failureFrom(fn () => $this->respondWithGoogle());

        $this->assertSame(1, $calls);
    }

    public function test_google_retries_an_outage_up_to_the_configured_limit(): void
    {
        Config::set('ai.provider_retry_attempts', 2);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response([], 500);
        });

        $this->failureFrom(fn () => $this->respondWithGoogle());

        $this->assertSame(3, $calls);
    }

    /* ------------------------------------------------------------------
     | Helpers
     |------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function respondWithGoogle(): array
    {
        return app(GoogleAiStudioProvider::class)->respond([
            'model' => 'gemini-3.5-flash',
            // Gemini takes the provider-neutral conversation, not a bare string.
            'input' => [['role' => 'user', 'content' => 'hello']],
        ]);
    }

    private function failureFrom(callable $call): AiProviderException
    {
        try {
            $call();
        } catch (AiProviderException $exception) {
            return $exception;
        }

        $this->fail('The provider did not raise an AiProviderException.');
    }
}
