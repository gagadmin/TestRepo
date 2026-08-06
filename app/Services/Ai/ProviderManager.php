<?php

namespace App\Services\Ai;

use App\Contracts\AiProvider;
use App\Services\Ai\Providers\AzureOpenAiResponsesProvider;
use App\Services\Ai\Providers\GoogleAiStudioProvider;
use App\Services\Ai\Providers\OpenAiResponsesProvider;
use RuntimeException;

class ProviderManager
{
    public function __construct(
        private readonly OpenAiResponsesProvider $openAi,
        private readonly AzureOpenAiResponsesProvider $azure,
        private readonly GoogleAiStudioProvider $google,
    ) {}

    public function current(): AiProvider
    {
        return match (config('ai.provider')) {
            'openai' => $this->openAi,
            'azure' => $this->azure,
            'google', 'gemini' => $this->google,
            default => throw new RuntimeException('The configured AI provider is not supported.'),
        };
    }
}
