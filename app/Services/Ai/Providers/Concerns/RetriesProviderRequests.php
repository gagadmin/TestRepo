<?php

namespace App\Services\Ai\Providers\Concerns;

use App\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Shared retry policy for the AI providers.
 *
 * The three providers speak different APIs and report their failures in
 * different vocabularies, but the decision of *whether to try again* is
 * identical and was
 * previously copied into each of them: honour the configured attempt budget,
 * repeat only a failure the provider itself classified as retryable, back off
 * exponentially unless the service supplied a Retry-After, and never wait more
 * than five seconds. Three copies meant a correction had to be made three
 * times, and Azure had already drifted away from the other two.
 *
 * Failure *mapping* deliberately stays with each provider: the status codes are
 * common but the codes, messages, and the question of whether an exhausted
 * quota can be distinguished from a rate limit are provider-specific.
 */
trait RetriesProviderRequests
{
    /**
     * Classify a failed response. Only `retryable` is consulted here; the
     * exception itself is raised by the caller.
     */
    abstract protected function failure(Response $response): AiProviderException;

    /** Message for a provider that could not be reached at all. */
    abstract protected function unreachableMessage(): string;

    /**
     * Run a request, repeating it while the failure is worth repeating.
     *
     * Returns the final response — successful, or a failure the caller should
     * map and throw. Only an unreachable service raises from here, because a
     * failed HTTP response still carries information the caller needs.
     *
     * @param  callable(): Response  $send
     */
    protected function requestWithRetries(callable $send): Response
    {
        $maxRetries = max(0, min((int) config('ai.provider_retry_attempts', 2), 5));

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $send();
            } catch (ConnectionException $exception) {
                if ($attempt === $maxRetries) {
                    throw new RuntimeException($this->unreachableMessage(), previous: $exception);
                }

                $this->pauseBeforeRetry($attempt);

                continue;
            }

            if ($response->successful()) {
                return $response;
            }

            // Repeating a rejected key or a malformed request cannot succeed,
            // wastes the caller's time, and can itself trip rate limiting.
            $failure = $this->failure($response);

            if (! $failure->retryable || $attempt === $maxRetries) {
                return $response;
            }

            $this->pauseBeforeRetry($attempt, $response);
        }

        throw new RuntimeException($this->unreachableMessage());
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
