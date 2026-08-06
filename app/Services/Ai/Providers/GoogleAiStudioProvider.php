<?php

namespace App\Services\Ai\Providers;

use App\Contracts\AiProvider;
use App\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleAiStudioProvider implements AiProvider
{
    public function name(): string
    {
        return 'google';
    }

    public function configured(): bool
    {
        return filled(config('ai.providers.google.api_key'));
    }

    public function respond(array $payload): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('The Google AI Studio provider is not configured.');
        }

        $response = $this->request($this->endpoint((string) $payload['model']), $this->toGeminiPayload($payload));

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('Google AI Studio returned an invalid response.');
        }

        return $this->normalizeResponse($data);
    }

    private function endpoint(string $model): string
    {
        $baseUrl = rtrim((string) config('ai.providers.google.base_url'), '/');
        $model = Str::after($model, 'models/');

        return "{$baseUrl}/models/".rawurlencode($model).':generateContent';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function toGeminiPayload(array $payload): array
    {
        $request = [
            'systemInstruction' => [
                'parts' => [['text' => (string) ($payload['instructions'] ?? '')]],
            ],
            'contents' => $this->toContents($payload['input'] ?? []),
            'generationConfig' => [
                'maxOutputTokens' => (int) ($payload['max_output_tokens'] ?? 1800),
            ],
        ];

        $declarations = collect($payload['tools'] ?? [])
            ->filter(fn (mixed $tool) => is_array($tool) && ($tool['type'] ?? null) === 'function')
            ->map(fn (array $tool) => Arr::whereNotNull([
                'name' => $tool['name'] ?? null,
                'description' => $tool['description'] ?? null,
                'parameters' => $this->normalizeSchema($tool['parameters'] ?? []),
            ]))
            ->values()
            ->all();

        if ($declarations !== []) {
            $request['tools'] = [['functionDeclarations' => $declarations]];
            $request['toolConfig'] = [
                'functionCallingConfig' => ['mode' => 'AUTO'],
            ];
        }

        return $request;
    }

    /**
     * Convert Ask GAHolding's provider-neutral conversation into Gemini Content objects.
     *
     * @param  array<int, mixed>  $input
     * @return array<int, array<string, mixed>>
     */
    private function toContents(array $input): array
    {
        $contents = [];
        $callNames = [];

        foreach ($input as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (isset($item['role']) && is_string($item['content'] ?? null)) {
                $this->appendPart(
                    $contents,
                    $item['role'] === 'assistant' ? 'model' : 'user',
                    ['text' => $item['content']]
                );

                continue;
            }

            if (($item['type'] ?? null) === 'message') {
                foreach ($item['content'] ?? [] as $content) {
                    if (is_array($content) && is_string($content['text'] ?? null)) {
                        $this->appendPart($contents, 'model', ['text' => $content['text']]);
                    }
                }

                continue;
            }

            if (($item['type'] ?? null) === 'function_call') {
                $callId = (string) ($item['call_id'] ?? $item['id'] ?? '');
                $name = (string) ($item['name'] ?? '');
                $arguments = json_decode((string) ($item['arguments'] ?? '{}'), true);
                $arguments = is_array($arguments) ? $arguments : [];
                $callNames[$callId] = $name;

                $providerPart = $item['provider_part'] ?? null;
                $this->appendPart($contents, 'model', is_array($providerPart) ? $providerPart : [
                    'functionCall' => [
                        'name' => $name,
                        'args' => $arguments,
                    ],
                ]);

                continue;
            }

            if (($item['type'] ?? null) === 'function_call_output') {
                $callId = (string) ($item['call_id'] ?? '');
                $result = json_decode((string) ($item['output'] ?? '{}'), true);

                if (! is_array($result) || array_is_list($result)) {
                    $result = ['result' => $result];
                }

                $this->appendPart($contents, 'user', [
                    'functionResponse' => [
                        'name' => $callNames[$callId] ?? 'unknown_tool',
                        'response' => $result,
                    ],
                ]);
            }
        }

        return $contents;
    }

    /**
     * @param  array<int, array<string, mixed>>  $contents
     * @param  array<string, mixed>  $part
     */
    private function appendPart(array &$contents, string $role, array $part): void
    {
        $lastIndex = array_key_last($contents);

        if ($lastIndex !== null && ($contents[$lastIndex]['role'] ?? null) === $role) {
            $contents[$lastIndex]['parts'][] = $part;

            return;
        }

        $contents[] = [
            'role' => $role,
            'parts' => [$part],
        ];
    }

    /**
     * Gemini supports a subset of OpenAPI schema and represents nullable fields
     * separately instead of accepting JSON Schema union types.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function normalizeSchema(array $schema): array
    {
        unset($schema['additionalProperties'], $schema['strict']);

        if (is_array($schema['type'] ?? null)) {
            $types = array_values(array_filter($schema['type'], fn (mixed $type) => $type !== 'null'));
            $schema['type'] = $types[0] ?? 'string';
            $schema['nullable'] = true;
        }

        if (is_array($schema['properties'] ?? null)) {
            $schema['properties'] = collect($schema['properties'])
                ->map(fn (mixed $property) => is_array($property) ? $this->normalizeSchema($property) : $property)
                ->all();
        }

        if (is_array($schema['items'] ?? null)) {
            $schema['items'] = $this->normalizeSchema($schema['items']);
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeResponse(array $data): array
    {
        $parts = data_get($data, 'candidates.0.content.parts', []);

        if (! is_array($parts)) {
            throw new RuntimeException('Google AI Studio returned an invalid response.');
        }

        $output = [];
        $text = [];

        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }

            if (is_string($part['text'] ?? null) && trim($part['text']) !== '') {
                $text[] = trim($part['text']);
            }

            if (is_array($part['functionCall'] ?? null)) {
                $call = $part['functionCall'];
                $output[] = [
                    'type' => 'function_call',
                    'call_id' => $call['id'] ?? 'google_'.Str::uuid(),
                    'name' => (string) ($call['name'] ?? ''),
                    'arguments' => json_encode(
                        is_array($call['args'] ?? null) ? $call['args'] : [],
                        JSON_THROW_ON_ERROR
                    ),
                    // Gemini 3 requires the original part, including its thought
                    // signature, to be replayed unchanged on the next turn.
                    'provider_part' => $part,
                ];
            }
        }

        if ($text !== []) {
            $output[] = [
                'type' => 'message',
                'role' => 'assistant',
                'content' => [[
                    'type' => 'output_text',
                    'text' => implode("\n\n", $text),
                ]],
            ];
        }

        if ($output === []) {
            $finishReason = data_get($data, 'candidates.0.finishReason');

            if ($finishReason === 'SAFETY') {
                throw new RuntimeException('Google AI Studio blocked the response under its safety policy.');
            }

            throw new RuntimeException('Google AI Studio returned no report response.');
        }

        return [
            'id' => $data['responseId'] ?? null,
            'output' => $output,
            'output_text' => implode("\n\n", $text),
            'usage' => [
                'input_tokens' => (int) data_get($data, 'usageMetadata.promptTokenCount', 0),
                'output_tokens' => (int) data_get($data, 'usageMetadata.candidatesTokenCount', 0),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function request(string $url, array $payload): Response
    {
        $maxRetries = max(0, min((int) config('ai.provider_retry_attempts', 2), 5));

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = Http::acceptJson()
                    ->withHeaders(['x-goog-api-key' => config('ai.providers.google.api_key')])
                    ->timeout(120)
                    ->post($url, $payload);
            } catch (ConnectionException $exception) {
                if ($attempt === $maxRetries) {
                    throw new RuntimeException('Google AI Studio could not be reached.', previous: $exception);
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

        throw new RuntimeException('Google AI Studio could not be reached.');
    }

    private function failure(Response $response): AiProviderException
    {
        $providerStatus = $response->json('error.status');
        $code = is_string($providerStatus) && $providerStatus !== ''
            ? Str::lower($providerStatus)
            : 'provider_error';

        if ($response->status() === 429) {
            return new AiProviderException(
                'Google AI Studio quota is exhausted or temporarily rate-limited. Review the Gemini API quota and billing, then try again.',
                'rate_limit_exceeded',
                429,
                true,
            );
        }

        if ($response->status() === 401) {
            return new AiProviderException(
                'Google AI Studio rejected the configured API key.',
                'authentication_error',
                401,
            );
        }

        if ($response->status() === 403) {
            return new AiProviderException(
                'The configured Google AI Studio key is not permitted to use the Gemini API or selected model.',
                'permission_denied',
                403,
            );
        }

        if ($response->status() === 404) {
            return new AiProviderException(
                'The configured Gemini model or API endpoint is unavailable.',
                'resource_not_found',
                404,
            );
        }

        if ($response->status() >= 500) {
            return new AiProviderException(
                'Google AI Studio is temporarily unavailable. Please try again shortly.',
                'provider_unavailable',
                $response->status(),
                true,
            );
        }

        return new AiProviderException(
            "The Google AI Studio request was rejected with status {$response->status()}.",
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
