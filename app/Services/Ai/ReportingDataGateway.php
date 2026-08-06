<?php

namespace App\Services\Ai;

use App\Data\ToolResult;
use App\Models\DataSource;
use App\Models\User;
use App\Services\Integrations\FreshserviceAnalyticsService;
use App\Services\Integrations\GoogleSearchConsoleService;
use App\Services\Integrations\IntegrationRequestFactory;
use App\Services\Integrations\IntegrationUrlGuard;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Retrieves data for a tool call.
 *
 * Handlers are implemented here, in code. An administrator selects which handler
 * a tool uses but cannot define a new one, and the target URL always comes from
 * the DataSource row via IntegrationUrlGuard — never from tool configuration.
 * That keeps "configurable tools" from becoming "arbitrary outbound HTTP".
 */
class ReportingDataGateway
{
    public function __construct(
        private readonly IntegrationUrlGuard $urlGuard,
        private readonly IntegrationRequestFactory $requests,
        private readonly GoogleSearchConsoleService $searchConsole,
        private readonly FreshserviceAnalyticsService $freshservice,
    ) {}

    public function fetch(DataSource $source, array $query, ?User $user = null): ToolResult
    {
        // Fall back to the source type when no handler is supplied, so any
        // caller predating configurable tools keeps working.
        $handler = $query['handler'] ?? $this->inferHandler($source);

        return $this->cached($source, $query, $user, fn () => match ($handler) {
            'google_search_console' => $this->fetchSearchConsole($source, $query),
            'freshservice_analytics' => $this->fetchFreshservice($source, $query),
            'generic_http' => $this->fetchGenericHttp($source, $query),
            default => throw new RuntimeException("The tool handler [{$handler}] is not implemented."),
        });
    }

    /**
     * Short-lived cache, keyed by source, parameters AND the caller's access
     * scope.
     *
     * The scope is part of the key deliberately. Two users with different
     * department visibility can receive different rows from the same source, so
     * a key that ignored identity would let one user's permitted data be served
     * to another.
     */
    private function cached(DataSource $source, array $query, ?User $user, callable $callback): ToolResult
    {
        $ttl = (int) config('ai.tool_cache_seconds', 300);

        if ($ttl <= 0) {
            return $callback();
        }

        $key = 'ai.tool:'.hash('sha256', json_encode([
            'source' => $source->id,
            'handler' => $query['handler'] ?? null,
            'report_type' => $query['report_type'] ?? null,
            'date_from' => $query['date_from'] ?? null,
            'date_to' => $query['date_to'] ?? null,
            'limit' => $query['limit'] ?? null,
            // Access scope, not just the user id: two users with identical
            // scope may safely share an entry.
            'scope' => $user ? [
                'roles' => $user->roles()->pluck('name')->sort()->values()->all(),
                'department' => $user->department,
            ] : null,
        ]));

        $cachedPayload = Cache::get($key);

        if (is_array($cachedPayload)) {
            return new ToolResult(
                data: $cachedPayload['data'],
                citations: $cachedPayload['citations'],
                // State plainly that this was reused, and when it was fetched,
                // so the model cannot present a stale figure as live.
                summary: [
                    ...$cachedPayload['summary'],
                    'served_from_cache' => true,
                    'originally_retrieved_at' => $cachedPayload['retrieved_at'],
                ],
            );
        }

        $result = $callback();

        Cache::put($key, [
            'data' => $result->data,
            'citations' => $result->citations,
            'summary' => $result->summary,
            'retrieved_at' => now()->toIso8601String(),
        ], $ttl);

        return $result;
    }

    private function inferHandler(DataSource $source): string
    {
        return match ($source->type) {
            'google_search_console' => 'google_search_console',
            'freshservice' => 'freshservice_analytics',
            default => 'generic_http',
        };
    }

    private function fetchSearchConsole(DataSource $source, array $query): ToolResult
    {
        $result = $this->searchConsole->analytics(
            $query,
            data_get($source->settings, 'site_url'),
        );

        return new ToolResult(
            data: ['rows' => $result['rows']],
            citations: [$this->citation($source)],
            summary: $result['summary'],
        );
    }

    /**
     * Freshservice ITSM.
     *
     * Routed through FreshserviceAnalyticsService rather than the generic HTTP
     * path. The generic path would hit /api/v2/tickets and return one raw page
     * of ticket objects — no date scoping and no counts — which is worse than
     * refusing, because the model would report a page size as a ticket total.
     */
    private function fetchFreshservice(DataSource $source, array $query): ToolResult
    {
        $analytics = $this->freshservice->analytics($source, array_filter([
            'date_from' => $query['date_from'] ?? null,
            'date_to' => $query['date_to'] ?? null,
        ]));

        // Send aggregates only. The raw ticket list is large, mostly irrelevant
        // to a counting question, and would crowd the context window.
        return new ToolResult(
            data: [
                'period' => [
                    'date_from' => $query['date_from'] ?? null,
                    'date_to' => $query['date_to'] ?? null,
                    'timezone' => $analytics['meta']['timezone'] ?? config('app.timezone'),
                ],
                'totals' => $analytics['summary'] ?? [],
                'by_status' => $analytics['overall_ticket_summary'] ?? [],
                'by_type' => $analytics['ticket_types'] ?? [],
                'unresolved_by_priority' => $analytics['unresolved_by_priority'] ?? [],
                'unresolved_by_group' => $analytics['unresolved_by_group'] ?? [],
                'unresolved_by_agent' => array_slice($analytics['unresolved_by_agent'] ?? [], 0, 25),
                'unresolved_by_category' => array_slice($analytics['unresolved_by_category'] ?? [], 0, 25),
                'ageing' => $analytics['ageing_bands']['bands'] ?? [],
            ],
            citations: [$this->citation($source)],
            summary: [
                'source_id' => $source->id,
                'analyzed_tickets' => $analytics['meta']['analyzed_tickets'] ?? null,
                'ticket_limit_reached' => $analytics['meta']['unresolved_ticket_limit_reached'] ?? false,
                'generated_at' => $analytics['meta']['generated_at'] ?? null,
            ],
        );
    }

    private function fetchGenericHttp(DataSource $source, array $query): ToolResult
    {
        $path = $source->settings['data_path'] ?? null;

        if (! $path) {
            throw new RuntimeException('The selected data source has no reporting endpoint configured.');
        }

        $url = rtrim((string) $source->base_url, '/').'/'.ltrim($path, '/');
        $this->urlGuard->assertAllowed($url);

        // Only forward parameters the endpoint understands; internal keys such
        // as handler and options must not leak into the query string.
        $parameters = array_filter([
            'date_from' => $query['date_from'] ?? null,
            'date_to' => $query['date_to'] ?? null,
            'limit' => $query['limit'] ?? null,
            'report_type' => $query['report_type'] ?? null,
        ], fn ($value) => $value !== null);

        $response = $this->requests->make($source->apiConfiguration)->get($url, $parameters);

        if (! $response->successful()) {
            throw new RuntimeException("The reporting endpoint returned HTTP {$response->status()}.");
        }

        $body = $response->body();

        if (strlen($body) > config('ai.tool_response_limit_bytes')) {
            throw new RuntimeException('The reporting endpoint response exceeded the configured safety limit.');
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('The reporting endpoint did not return a JSON object or array.');
        }

        return new ToolResult(
            data: $data,
            citations: [$this->citation($source)],
            summary: [
                'source_id' => $source->id,
                'top_level_keys' => array_slice(array_keys($data), 0, 20),
                'response_bytes' => strlen($body),
            ],
        );
    }

    /**
     * @return array<string, int|string>
     */
    private function citation(DataSource $source): array
    {
        return [
            'source_id' => $source->id,
            'source_name' => $source->name,
            'source_type' => $source->type,
            'retrieved_at' => now()->toIso8601String(),
        ];
    }
}
