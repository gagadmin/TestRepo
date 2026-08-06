<?php

namespace App\Services\Ai;

use App\Contracts\AiTool;
use App\Models\AiToolDefinition;
use App\Services\Ai\Providers\OpenAiResponsesProvider;
use App\Services\Ai\Tools\ConfiguredReportingTool;
use App\Services\Ai\Tools\OpenAiWebSearchTool;
use App\Services\Ai\Tools\WebSearchTool;
use App\Services\Integrations\WebSearchConnector;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The approved tool set, loaded from configuration rather than hard-coded.
 *
 * Previously this list lived in the constructor, so connecting a new source type
 * to the assistant required a code change and a deploy. That is why Freshservice
 * showed as connected under Data Sources while the assistant denied that any
 * ITSM connector existed — two independent registries of "which source types are
 * usable" had drifted apart.
 *
 * It remains an allow list. `get()` refuses anything not present and enabled,
 * and a row whose handler is not implemented in code is skipped rather than
 * trusted.
 */
class ToolRegistry
{
    /** @var array<string, AiTool>|null Memoised for the request. */
    private ?array $tools = null;

    public function __construct(
        private readonly ReportingDataGateway $gateway,
        private readonly WebSearchConnector $webSearch,
        private readonly OpenAiResponsesProvider $openAi,
    ) {}

    /**
     * @return array<string, AiTool>
     */
    private function tools(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $this->tools = AiToolDefinition::query()
            ->enabled()
            ->ordered()
            ->get()
            ->filter(function (AiToolDefinition $definition) {
                // An unimplemented handler cannot be honoured. Skip it loudly
                // rather than exposing a tool that would always throw.
                if (! $definition->hasValidHandler()) {
                    Log::warning('Skipping AI tool with an unknown handler.', [
                        'tool' => $definition->name,
                        'handler' => $definition->handler,
                    ]);

                    return false;
                }

                // Standalone tools (e.g. web_search) resolve no DataSource, so
                // the source-types requirement does not apply. Instead they must
                // have a configured provider — skip an enabled-but-unconfigured
                // one rather than exposing a tool that would always throw.
                if ($definition->isStandalone()) {
                    if (! $definition->providerConfigured()) {
                        Log::warning('Skipping standalone AI tool with no provider configured.', [
                            'tool' => $definition->name,
                            'handler' => $definition->handler,
                        ]);

                        return false;
                    }

                    return true;
                }

                // With no source types, no source can ever resolve.
                return filled($definition->source_types);
            })
            ->mapWithKeys(fn (AiToolDefinition $definition) => [
                $definition->name => $this->build($definition),
            ])
            ->all();

        return $this->tools;
    }

    /**
     * Construct the runtime tool for a definition.
     *
     * The handler decides the class: standalone handlers get their dedicated
     * tool wired with the provider configuration stored on the row; every other
     * handler is a DataSource-backed reporting tool.
     */
    private function build(AiToolDefinition $definition): AiTool
    {
        if ($definition->handler === 'openai_web_search') {
            return new OpenAiWebSearchTool(
                $this->openAi,
                $definition->options ?? [],
                $definition->name,
                $definition->description,
            );
        }

        if ($definition->handler === 'web_search') {
            return new WebSearchTool(
                $this->webSearch,
                $this->webSearchConfig($definition),
                $definition->name,
                $definition->description,
            );
        }

        return new ConfiguredReportingTool(
            $definition->name,
            $definition->description,
            $definition->source_types,
            $definition->handler,
            $this->gateway,
            $definition->options ?? [],
        );
    }

    /**
     * Merge the admin-configured provider settings over the config defaults.
     *
     * Non-secret settings come from `options`, the API key from the encrypted
     * `secret_options`. Hard safety limits (response cap) fall back to
     * config/web_search.php so an administrator cannot remove them.
     *
     * @return array<string, mixed>
     */
    private function webSearchConfig(AiToolDefinition $definition): array
    {
        $options = $definition->options ?? [];
        $secrets = $definition->secret_options ?? [];

        return [
            'endpoint' => $options['endpoint'] ?? null,
            'allowed_hosts' => $options['allowed_hosts'] ?? [],
            'auth_scheme' => $options['auth_scheme'] ?? config('web_search.auth_scheme', 'bearer'),
            'key_header' => $options['key_header'] ?? config('web_search.key_header', 'X-API-Key'),
            'api_key' => $secrets['api_key'] ?? null,
            'max_results' => (int) ($options['max_results'] ?? config('web_search.max_results', 5)),
            'timeout_seconds' => (int) ($options['timeout_seconds'] ?? config('web_search.timeout_seconds', 15)),
            'retry_attempts' => (int) config('web_search.retry_attempts', 1),
            'response_limit_bytes' => (int) config('web_search.response_limit_bytes', 1_000_000),
        ];
    }

    public function definitions(): array
    {
        return array_map(
            fn (AiTool $tool) => $tool->definition(),
            array_values($this->tools())
        );
    }

    public function names(): array
    {
        return array_keys($this->tools());
    }

    public function get(string $name): AiTool
    {
        return $this->tools()[$name]
            ?? throw new InvalidArgumentException("Tool [{$name}] is not approved.");
    }

    public function has(string $name): bool
    {
        return isset($this->tools()[$name]);
    }

    /**
     * Tools that answer from outside the connected data sources (e.g. web
     * search). The assistant lists these separately so it does not treat "no
     * data source covers this" as "capability unavailable".
     *
     * @return array<int, AiTool>
     */
    public function standaloneTools(): array
    {
        return array_values(array_filter(
            $this->tools(),
            fn (AiTool $tool) => $tool instanceof WebSearchTool
                || $tool instanceof OpenAiWebSearchTool,
        ));
    }

    /**
     * Every DataSource type reachable through an enabled tool.
     *
     * The AI status endpoint uses this so the UI cannot advertise a connected
     * source the assistant has no way to read — the mismatch that made the
     * Freshservice chip appear while ITSM questions were refused.
     *
     * @return array<int, string>
     */
    public function reachableSourceTypes(): array
    {
        return AiToolDefinition::query()
            ->enabled()
            ->get(['source_types', 'handler'])
            ->filter(fn (AiToolDefinition $definition) => $definition->hasValidHandler())
            ->flatMap(fn (AiToolDefinition $definition) => $definition->source_types ?? [])
            ->unique()
            ->values()
            ->all();
    }

    /** Drop the memoised set after an administrator edits the configuration. */
    public function flush(): void
    {
        $this->tools = null;
    }
}
