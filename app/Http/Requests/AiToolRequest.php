<?php

namespace App\Http\Requests;

use App\Models\AiToolDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AiToolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('integrations.manage') ?? false;
    }

    /** A standalone handler (e.g. web_search) reads no DataSource. */
    private function isStandalone(): bool
    {
        return (AiToolDefinition::HANDLERS[(string) $this->input('handler')]['standalone'] ?? false) === true;
    }

    /** The AI provider a handler reuses (e.g. 'openai'), or null for a per-tool provider. */
    private function usesAiProvider(): ?string
    {
        return AiToolDefinition::HANDLERS[(string) $this->input('handler')]['uses_ai_provider'] ?? null;
    }

    protected function prepareForValidation(): void
    {
        $standalone = $this->isStandalone();

        $this->merge([
            // Tool names become function names sent to the model, so normalise
            // to the snake_case shape the providers accept.
            'name' => Str::of((string) $this->input('name'))
                ->lower()
                ->replaceMatches('/[^a-z0-9_]+/', '_')
                ->trim('_')
                ->toString(),
            'label' => trim((string) $this->input('label')),
            'description' => trim((string) $this->input('description')),
            // Standalone tools carry no source types; force an empty list so the
            // NOT NULL json column stays valid and the min:1 rule is skipped.
            'source_types' => $standalone ? [] : collect($this->input('source_types', []))
                ->filter(fn (mixed $type) => is_string($type))
                ->unique()
                ->values()
                ->all(),
        ]);

        if (! $standalone) {
            return;
        }

        if ($this->usesAiProvider()) {
            // Reuses an application AI provider: only behavioural options, no key.
            $this->merge(['options' => $this->normalizedProviderOptions()]);

            return;
        }

        // Per-tool search-API provider: endpoint, hosts, key.
        $this->merge([
            'options' => $this->normalizedSearchApiOptions(),
            // Normalise to null so "leave blank to keep" is unambiguous. On
            // create the required rule still rejects a null key; on update the
            // controller keeps the stored key when this is null.
            'api_key' => blank($this->input('api_key')) ? null : $this->input('api_key'),
        ]);
    }

    /**
     * Behavioural options for a handler that reuses an AI provider's key.
     *
     * @return array<string, mixed>
     */
    private function normalizedProviderOptions(): array
    {
        $options = (array) $this->input('options', []);

        return [
            'model' => trim((string) ($options['model'] ?? '')) ?: null,
            'max_output_tokens' => (int) ($options['max_output_tokens'] ?? 1500),
            'tool_type' => in_array($options['tool_type'] ?? 'web_search', ['web_search', 'web_search_preview'], true)
                ? $options['tool_type']
                : 'web_search',
        ];
    }

    /**
     * Provider settings for a per-tool search-API handler.
     *
     * @return array<string, mixed>
     */
    private function normalizedSearchApiOptions(): array
    {
        $options = (array) $this->input('options', []);

        $hosts = collect($options['allowed_hosts'] ?? [])
            // Accept either an array or a comma-separated string from the UI.
            ->when(
                is_string($options['allowed_hosts'] ?? null),
                fn () => collect(explode(',', (string) $options['allowed_hosts']))
            )
            ->map(fn ($host) => strtolower(trim((string) $host)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'endpoint' => trim((string) ($options['endpoint'] ?? '')) ?: null,
            'allowed_hosts' => $hosts,
            'auth_scheme' => in_array($options['auth_scheme'] ?? 'bearer', ['bearer', 'header'], true)
                ? $options['auth_scheme']
                : 'bearer',
            'key_header' => trim((string) ($options['key_header'] ?? 'X-API-Key')) ?: 'X-API-Key',
            'max_results' => (int) ($options['max_results'] ?? 5),
            'timeout_seconds' => (int) ($options['timeout_seconds'] ?? 15),
            'cache_seconds' => (int) ($options['cache_seconds'] ?? 300),
        ];
    }

    public function rules(): array
    {
        $rules = [
            'name' => [
                'required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('ai_tools', 'name')->ignore($this->route('tool')),
            ],
            'label' => ['required', 'string', 'max:120'],

            // The description is the only thing telling the model when to call
            // this tool, so a vague one silently breaks retrieval.
            'description' => ['required', 'string', 'min:40', 'max:2000'],

            /*
             * Handlers are restricted to those implemented in code. This is the
             * security boundary: an administrator composes approved
             * capabilities and cannot introduce new fetch behaviour, so
             * "configurable tools" never becomes "arbitrary outbound HTTP".
             */
            'handler' => ['required', 'string', Rule::in(array_keys(AiToolDefinition::HANDLERS))],

            'is_enabled' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,9999'],
        ];

        if (! $this->isStandalone()) {
            $rules['source_types'] = ['required', 'array', 'min:1', 'max:10'];
            $rules['source_types.*'] = [
                'required', 'string',
                // Only registered integration types, so a typo cannot create a
                // tool that silently matches nothing.
                Rule::in(array_keys(config('integrations.types', []))),
            ];
            // Reserved for handler-specific reporting options; not set by the UI.
            $rules['options'] = ['nullable', 'array'];

            return $rules;
        }

        // Standalone: no DataSource types.
        $rules['source_types'] = ['array'];
        $rules['options'] = ['required', 'array'];

        if ($this->usesAiProvider()) {
            // Reuses the application provider's key — only behavioural options.
            $rules['options.model'] = ['required', 'string', 'max:100'];
            $rules['options.max_output_tokens'] = ['nullable', 'integer', 'between:256,8000'];
            $rules['options.tool_type'] = ['nullable', Rule::in(['web_search', 'web_search_preview'])];

            return $rules;
        }

        // Per-tool search-API provider.
        $rules['options.endpoint'] = ['required', 'string', 'url', 'starts_with:https://', 'max:2000'];
        $rules['options.allowed_hosts'] = ['required', 'array', 'min:1', 'max:20'];
        $rules['options.allowed_hosts.*'] = ['required', 'string', 'max:255', 'regex:/^[a-z0-9.-]+$/'];
        $rules['options.auth_scheme'] = ['required', Rule::in(['bearer', 'header'])];
        $rules['options.key_header'] = ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]*$/'];
        $rules['options.max_results'] = ['required', 'integer', 'between:1,10'];
        $rules['options.timeout_seconds'] = ['required', 'integer', 'between:1,60'];
        $rules['options.cache_seconds'] = ['required', 'integer', 'between:0,86400'];
        // Required when creating; optional on update (blank = keep stored).
        $rules['api_key'] = [
            $this->isMethod('post') ? 'required' : 'nullable',
            'string', 'max:4000',
        ];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Use lowercase letters, numbers and underscores, starting with a letter.',
            'description.min' => 'Describe clearly which questions this tool answers — the model relies on this text to decide when to call it.',
            'handler.in' => 'Choose one of the implemented handlers.',
            'source_types.*.in' => 'That data source type is not registered in this application.',
            'options.model.required' => 'Enter the OpenAI model to use for web search (for example gpt-4o).',
            'options.endpoint.starts_with' => 'The provider endpoint must be an HTTPS URL.',
            'options.allowed_hosts.required' => 'List at least one host the search provider is allowed to use.',
            'options.allowed_hosts.*.regex' => 'Enter bare hostnames such as api.search-provider.com.',
            'api_key.required' => 'A provider API key is required to create a web search tool.',
        ];
    }
}
