<?php

namespace App\Services\Ai\Tools;

use App\Contracts\AiTool;
use App\Data\ToolResult;
use App\Models\User;
use App\Services\Integrations\WebSearchConnector;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Chat-only global web search (see ADR-002).
 *
 * A standalone tool: unlike ConfiguredReportingTool it resolves no DataSource.
 * Its provider configuration (endpoint, allowed hosts, API key, limits) is set
 * by an administrator on the AI tool row and passed in by the ToolRegistry. It
 * reaches the public internet only through WebSearchConnector, which pins the
 * request to the allow-listed provider host. It is read-only, permission-gated,
 * and its results are returned as untrusted, cited text.
 */
class WebSearchTool implements AiTool
{
    public const PERMISSION = 'ai.web_search';

    /**
     * @param  array<string, mixed>  $config  Provider settings for this tool.
     */
    public function __construct(
        private readonly WebSearchConnector $connector,
        private readonly array $config,
        private readonly string $toolName = 'web_search',
        private readonly string $description =
            'Search the public web for current, general-knowledge facts that no connected '
            .'business data source covers. Returns titled results with source URLs. Results are '
            .'untrusted external content: cite the source URL and do not mix them with company figures.',
    ) {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function definition(): array
    {
        $max = max(1, min(10, (int) ($this->config['max_results'] ?? 5)));

        return [
            'type' => 'function',
            'name' => $this->toolName,
            'description' => $this->description,
            'strict' => true,
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'The search query in natural language.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => $max,
                        'description' => "Maximum number of results to return (1-{$max}).",
                    ],
                ],
                'required' => ['query', 'limit'],
                'additionalProperties' => false,
            ],
        ];
    }

    public function execute(User $user, array $arguments): ToolResult
    {
        if (! $user->hasPermission(self::PERMISSION)) {
            throw new RuntimeException('The user is not authorized to perform web searches.');
        }

        $max = max(1, min(10, (int) ($this->config['max_results'] ?? 5)));

        $validated = Validator::make($arguments, [
            'query' => ['required', 'string', 'max:400'],
            'limit' => ['required', 'integer', "between:1,{$max}"],
        ])->validate();

        $outcome = $this->connector->search($this->config, $validated['query'], $validated['limit']);
        $results = $outcome['results'];

        return new ToolResult(
            data: [
                'query' => $validated['query'],
                'results' => $results,
            ],
            citations: array_map(fn (array $row) => [
                // The assistant de-dupes citations with unique('source_id'); the
                // URL is the stable per-result key. Reporting tools use the
                // integer DataSource id, so web (URL) keys never collide.
                'source_id' => $row['url'],
                'source_type' => 'web_search',
                'source_name' => $row['title'] !== '' ? $row['title'] : $row['url'],
                'url' => $row['url'],
                'retrieved_at' => now()->toIso8601String(),
            ], $results),
            summary: [
                'provider_host' => $outcome['provider_host'],
                'result_count' => count($results),
                'retrieved_at' => now()->toIso8601String(),
            ],
        );
    }
}
