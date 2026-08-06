<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An administrator-configurable AI tool.
 *
 * Named `AiToolDefinition` rather than `AiTool` to avoid colliding with the
 * `App\Contracts\AiTool` interface that the runtime tool classes implement.
 * This model is the configuration; that interface is the behaviour.
 */
class AiToolDefinition extends Model
{
    protected $table = 'ai_tools';

    /**
     * Retrieval strategies implemented in code.
     *
     * This is the security boundary of the whole feature: an administrator
     * chooses from these, and cannot introduce a new one through the UI. A
     * handler decides HOW data is fetched; the DataSource decides from WHERE,
     * and IntegrationUrlGuard still vets every URL.
     */
    public const HANDLERS = [
        'generic_http' => [
            'label' => 'Generic HTTP endpoint',
            'description' => 'Calls the reporting path configured on the data source and returns the raw JSON.',
            'requires_data_path' => true,
        ],
        'google_search_console' => [
            'label' => 'Google Search Console',
            'description' => 'Queries Search Console analytics for the configured site.',
            'requires_data_path' => false,
        ],
        'freshservice_analytics' => [
            'label' => 'Freshservice ITSM analytics',
            'description' => 'Aggregates Freshservice tickets into counts by status, priority, type, group and agent.',
            'requires_data_path' => false,
        ],
        'web_search' => [
            'label' => 'Global web search (search API)',
            'description' => 'Searches the public web through one configured search-API provider. Reads no data '
                .'source; the provider endpoint, allowed hosts and API key are set on this tool.',
            'requires_data_path' => false,
            // Standalone handlers resolve no DataSource. They carry their own
            // provider configuration in `options` / `secret_options` instead of
            // requiring `source_types`, and IntegrationUrlGuard plus an explicit
            // host allow-list still vet every outbound request (see ADR-002).
            'standalone' => true,
        ],
        'openai_web_search' => [
            'label' => 'Global web search (OpenAI)',
            'description' => "Searches the public web using OpenAI's Responses API web search tool. Reuses the "
                .'OpenAI API key already configured for this application; no separate key or endpoint is needed.',
            'requires_data_path' => false,
            'standalone' => true,
            // Reuses an existing AI provider's credentials rather than a
            // per-tool key. The registry routes it through that provider.
            'uses_ai_provider' => 'openai',
        ],
    ];

    protected $fillable = [
        'name', 'label', 'description', 'handler',
        'source_types', 'is_enabled', 'sort_order', 'options', 'secret_options', 'updated_by',
    ];

    /**
     * Never serialize the provider secret. Mirrors ApiConfiguration, which
     * hides encrypted_credentials; the controller exposes only a boolean saying
     * whether a key is present.
     */
    protected $hidden = [
        'secret_options',
    ];

    protected function casts(): array
    {
        return [
            'source_types' => 'array',
            'options' => 'array',
            // Encrypted at rest, like ApiConfiguration.encrypted_credentials.
            'secret_options' => 'encrypted:array',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** A standalone tool reads no DataSource; it carries its own provider config. */
    public function isStandalone(): bool
    {
        return (self::HANDLERS[$this->handler]['standalone'] ?? false) === true;
    }

    /**
     * The AI provider whose credentials this handler reuses, if any.
     *
     * A handler like openai_web_search does not take a per-tool key — it rides
     * on an already-configured provider (config/ai.php). Returns the provider
     * key (e.g. 'openai') or null when the tool carries its own credentials.
     */
    public function usesAiProvider(): ?string
    {
        return self::HANDLERS[$this->handler]['uses_ai_provider'] ?? null;
    }

    /**
     * Whether a standalone tool has enough provider configuration to run.
     *
     * Used the same way hasValidHandler() is: the registry skips an enabled
     * standalone tool that is not yet configured rather than exposing one that
     * would always throw. Non-standalone tools are considered configured — their
     * readiness is measured by connected sources instead.
     */
    public function providerConfigured(): bool
    {
        if (! $this->isStandalone()) {
            return true;
        }

        // Handlers that reuse an AI provider are ready when that provider's key
        // is configured for the application — the admin sets no per-tool key.
        if ($provider = $this->usesAiProvider()) {
            return filled(config("ai.providers.{$provider}.api_key"));
        }

        $options = $this->options ?? [];
        $secrets = $this->secret_options ?? [];

        return filled($options['endpoint'] ?? null)
            && filled($options['allowed_hosts'] ?? null)
            && filled($secrets['api_key'] ?? null);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function handlerLabel(): string
    {
        return self::HANDLERS[$this->handler]['label'] ?? $this->handler;
    }

    /** Whether the configured handler is actually implemented. */
    public function hasValidHandler(): bool
    {
        return array_key_exists($this->handler, self::HANDLERS);
    }

    /**
     * Connected data sources this tool could read, ignoring per-user access.
     *
     * Used by the admin page to warn that a tool is enabled but has nothing to
     * talk to — the exact condition that made the assistant deny ITSM access.
     */
    public function reachableSourceCount(): int
    {
        return DataSource::query()
            ->where('status', 'connected')
            ->whereIn('type', $this->source_types ?? [])
            ->count();
    }
}
