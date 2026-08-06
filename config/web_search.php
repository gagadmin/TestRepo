<?php

/*
|--------------------------------------------------------------------------
| Global web search tool (chat-only) — defaults & safety limits
|--------------------------------------------------------------------------
|
| The web_search capability (ADR-002) is configured per tool on the AI Tools
| admin page: an administrator sets the provider endpoint, allowed hosts and
| API key there. Those values live on the ai_tools row (endpoint/hosts in
| `options`, the API key encrypted in `secret_options`) — NOT here.
|
| This file provides only the fallback defaults used when a tool omits an
| optional setting, plus the hard response-size cap that an administrator
| cannot raise from the UI. The connector always enforces the host allow-list
| and IntegrationUrlGuard regardless of these values.
|
*/

return [
    // Default auth presentation when a tool does not specify one.
    'auth_scheme' => env('WEB_SEARCH_AUTH_SCHEME', 'bearer'), // bearer | header
    'key_header' => env('WEB_SEARCH_KEY_HEADER', 'X-API-Key'),

    // Defaults for optional per-tool limits.
    'max_results' => (int) env('WEB_SEARCH_MAX_RESULTS', 5),
    'timeout_seconds' => (int) env('WEB_SEARCH_TIMEOUT_SECONDS', 15),
    'retry_attempts' => (int) env('WEB_SEARCH_RETRY_ATTEMPTS', 1),

    // Hard ceiling on a provider response, enforced in code. Not editable from
    // the admin UI: it is a safety limit, not a preference.
    'response_limit_bytes' => (int) env('WEB_SEARCH_RESPONSE_LIMIT_BYTES', 1_000_000),

    // Default OpenAI model for the `openai_web_search` handler when a tool does
    // not set one. Must be a model that supports the Responses API web search
    // tool — confirm against the current OpenAI model list.
    'openai_model' => env('WEB_SEARCH_OPENAI_MODEL', 'gpt-4o'),
];
