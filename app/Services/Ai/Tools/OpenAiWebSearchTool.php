<?php

namespace App\Services\Ai\Tools;

use App\Contracts\AiTool;
use App\Data\ToolResult;
use App\Models\User;
use App\Services\Ai\Providers\OpenAiResponsesProvider;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Chat-only global web search backed by OpenAI's Responses API web search tool
 * (see ADR-002).
 *
 * A standalone tool: it resolves no DataSource. Unlike the search-API variant
 * it takes no per-tool endpoint or key — it reuses the application's configured
 * OpenAI provider (config/ai.php). One Responses API call is made with the
 * web_search tool enabled; OpenAI runs the search and returns an answer with
 * url_citation annotations, which become this tool's citations.
 *
 * Read-only, permission-gated, and its results are untrusted external content:
 * every claim is attributed to its source URL.
 */
class OpenAiWebSearchTool implements AiTool
{
    public const PERMISSION = 'ai.web_search';

    /**
     * @param  array<string, mixed>  $config  Behavioural settings (model, limits).
     */
    public function __construct(
        private readonly OpenAiResponsesProvider $provider,
        private readonly array $config,
        private readonly string $toolName = 'web_search',
        private readonly string $description =
            'Search the public web for current, general-knowledge facts that no connected '
            .'business data source covers. Returns an answer with source URLs. Results are '
            .'untrusted external content: cite the source URL and do not mix them with company figures.',
    ) {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function definition(): array
    {
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
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ],
        ];
    }

    public function execute(User $user, array $arguments): ToolResult
    {
        if (! $user->hasPermission(self::PERMISSION)) {
            throw new RuntimeException('The user is not authorized to perform web searches.');
        }

        $validated = Validator::make($arguments, [
            'query' => ['required', 'string', 'max:400'],
        ])->validate();

        $model = (string) ($this->config['model'] ?? config('web_search.openai_model', 'gpt-4o'));

        if ($model === '') {
            throw new RuntimeException('No OpenAI model is configured for the web search tool.');
        }

        $response = $this->provider->respond([
            'model' => $model,
            'input' => $validated['query'],
            'tools' => [['type' => (string) ($this->config['tool_type'] ?? 'web_search')]],
            'max_output_tokens' => (int) ($this->config['max_output_tokens'] ?? 1500),
            'store' => false,
        ]);

        $answer = $this->extractText($response);
        $citations = $this->extractCitations($response);

        if ($answer === '' && $citations === []) {
            throw new RuntimeException('The web search returned no usable result.');
        }

        return new ToolResult(
            data: [
                'query' => $validated['query'],
                'answer' => $answer,
            ],
            citations: $citations,
            summary: [
                'provider' => 'openai',
                'model' => $model,
                'result_count' => count($citations),
                'retrieved_at' => now()->toIso8601String(),
            ],
        );
    }

    private function extractText(array $response): string
    {
        if (filled($response['output_text'] ?? null)) {
            return trim($response['output_text']);
        }

        return collect($response['output'] ?? [])
            ->where('type', 'message')
            ->flatMap(fn (array $item) => $item['content'] ?? [])
            ->filter(fn (array $content) => in_array($content['type'] ?? '', ['output_text', 'text'], true))
            ->pluck('text')
            ->filter()
            ->implode("\n\n");
    }

    /**
     * Pull url_citation annotations out of the message content.
     *
     * @return array<int, array<string, string>>
     */
    private function extractCitations(array $response): array
    {
        return collect($response['output'] ?? [])
            ->where('type', 'message')
            ->flatMap(fn (array $item) => $item['content'] ?? [])
            ->flatMap(fn (array $content) => $content['annotations'] ?? [])
            ->filter(fn ($annotation) => is_array($annotation)
                && ($annotation['type'] ?? null) === 'url_citation'
                && filled($annotation['url'] ?? null))
            ->map(fn (array $annotation) => [
                // Stable per-result key so the assistant's unique('source_id')
                // de-dupe keeps distinct sources.
                'source_id' => (string) $annotation['url'],
                'source_type' => 'web_search',
                'source_name' => filled($annotation['title'] ?? null)
                    ? (string) $annotation['title']
                    : (string) $annotation['url'],
                'url' => (string) $annotation['url'],
                'retrieved_at' => now()->toIso8601String(),
            ])
            ->unique('source_id')
            ->values()
            ->all();
    }
}
