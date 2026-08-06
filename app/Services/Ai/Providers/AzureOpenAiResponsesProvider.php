<?php

namespace App\Services\Ai\Providers;

use App\Contracts\AiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AzureOpenAiResponsesProvider implements AiProvider
{
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

        try {
            $response = Http::withHeaders([
                'api-key' => config('ai.providers.azure.api_key'),
            ])
                ->acceptJson()
                ->timeout(120)
                ->retry(2, 500, throw: false)
                ->post(config('ai.providers.azure.responses_url'), $payload);
        } catch (ConnectionException) {
            throw new RuntimeException('The Azure OpenAI service could not be reached.');
        }

        if (! $response->successful()) {
            throw new RuntimeException("The Azure OpenAI request failed with status {$response->status()}.");
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('The Azure OpenAI service returned an invalid response.');
        }

        return $data;
    }
}
