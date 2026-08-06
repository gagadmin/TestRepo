<?php

return [
    'provider' => env('AI_PROVIDER', 'google'),
    'model' => env('AI_MODEL', 'gemini-3.5-flash'),
    'reasoning_effort' => env('AI_REASONING_EFFORT', 'low'),
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 1800),
    'max_tool_rounds' => (int) env('AI_MAX_TOOL_ROUNDS', 4),
    'history_messages' => (int) env('AI_HISTORY_MESSAGES', 20),
    'tool_response_limit_bytes' => (int) env('AI_TOOL_RESPONSE_LIMIT_BYTES', 500000),
    'provider_retry_attempts' => (int) env('AI_PROVIDER_RETRY_ATTEMPTS', 2),
    'provider_retry_base_delay_ms' => (int) env('AI_PROVIDER_RETRY_BASE_DELAY_MS', 500),

    /*
    |--------------------------------------------------------------------------
    | Tool result cache
    |--------------------------------------------------------------------------
    |
    | Seconds a tool result is reused. Keyed by source, parameters AND the
    | caller's access scope, so a cached row cannot cross a permission
    | boundary. Short by design: an operational question deserves a current
    | answer, and a reused result is labelled as such in the tool summary so
    | the model cannot present it as live. Set 0 to disable.
    |
    */
    'tool_cache_seconds' => (int) env('AI_TOOL_CACHE_SECONDS', 300),

    /*
    |--------------------------------------------------------------------------
    | Correction memory
    |--------------------------------------------------------------------------
    |
    | The model's weights never change. Approved corrections are injected into
    | the prompt, which produces learning-like behaviour through retrieval.
    | Only approved rows are ever used: unreviewed text reaching the prompt
    | would be a prompt-injection path affecting every user.
    |
    */
    'corrections' => [
        'enabled' => (bool) env('AI_CORRECTIONS_ENABLED', true),
        // How many approved corrections may be injected into one prompt.
        'max_injected' => (int) env('AI_CORRECTIONS_MAX_INJECTED', 8),
    ],

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'responses_url' => env('OPENAI_RESPONSES_URL', 'https://api.openai.com/v1/responses'),
            'organization' => env('OPENAI_ORGANIZATION'),
            'project' => env('OPENAI_PROJECT'),
        ],
        'azure' => [
            'api_key' => env('AZURE_OPENAI_API_KEY'),
            'responses_url' => env('AZURE_OPENAI_RESPONSES_URL'),
        ],
        'google' => [
            'api_key' => env('GEMINI_API_KEY', env('GOOGLE_AI_STUDIO_API_KEY')),
            'base_url' => env('GOOGLE_AI_STUDIO_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        ],
    ],
];
