<?php

namespace App\Services\Integrations;

use App\Models\DataSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FreshserviceAnalyticsService
{
    private const DEFAULT_STATUSES = [
        2 => 'Open',
        3 => 'On Hold',
        4 => 'Resolved',
        5 => 'Closed',
    ];

    private const DEFAULT_PRIORITIES = [
        1 => 'Low',
        2 => 'Medium',
        3 => 'High',
        4 => 'Urgent',
    ];

    public function __construct(
        private readonly IntegrationUrlGuard $urlGuard,
        private readonly IntegrationRequestFactory $requests,
    ) {}

    public function analytics(DataSource $source, array $filters = []): array
    {
        $source->loadMissing('apiConfiguration');
        $baseUrl = rtrim((string) $source->base_url, '/');
        $this->urlGuard->assertAllowed($baseUrl);

        try {
            $fields = $this->get($source, "{$baseUrl}/api/v2/ticket_form_fields")->json('ticket_fields', []);
        } catch (RuntimeException) {
            $fields = [];
        }
        $statuses = $this->fieldChoices($fields, 'status', self::DEFAULT_STATUSES);
        $priorities = $this->fieldChoices($fields, 'priority', self::DEFAULT_PRIORITIES);
        [$tickets, $overallSummary, $ticketTotal, $truncated] = $this->ticketsByStatus(
            $source,
            "{$baseUrl}/api/v2/tickets/filter",
            $statuses,
            $filters,
        );

        // Load agent and group names from cache with fallback to API
        $agentNames = $this->loadCachedNames($source->id, 'agent');
        $groupNames = $this->loadCachedNames($source->id, 'group');

        // Fallback to API if cache is empty (during initial deployment)
        if (empty($agentNames)) {
            try {
                $agents = $this->paginated(
                    $source,
                    "{$baseUrl}/api/v2/agents",
                    [],
                    'agents',
                    config('integrations.freshservice.max_directory_pages', 50),
                );
                $agentNames = $this->namesById($agents, 'agent');
            } catch (RuntimeException) {
                $agentNames = [];
            }
        }

        if (empty($groupNames)) {
            try {
                $groups = $this->paginated(
                    $source,
                    "{$baseUrl}/api/v2/groups",
                    [],
                    'groups',
                    config('integrations.freshservice.max_directory_pages', 50),
                );
                $groupNames = $this->namesById($groups, 'group');
            } catch (RuntimeException) {
                $groupNames = [];
            }
        }

        $onHoldStatusIds = collect(data_get($source->settings, 'on_hold_status_ids', [3]))
            ->filter(fn (mixed $status) => is_numeric($status))
            ->map(fn (mixed $status) => (int) $status)
            ->values()
            ->all();

        // Type totals cover ALL tickets in the window, including ones already
        // resolved, so "how many service requests today" is answerable.
        $ticketTypes = $this->ticketTotalsByType(
            $source,
            "{$baseUrl}/api/v2/tickets/filter",
            $this->typeChoices($fields),
            $filters,
        );

        return [
            ...$this->summarize(
                $tickets,
                $statuses,
                $priorities,
                $agentNames,
                $groupNames,
                $onHoldStatusIds,
                $overallSummary,
                $ticketTotal,
                $truncated,
            ),
            'ticket_types' => $ticketTypes,
            'filters' => [
                'date_from' => $filters['date_from'] ?? null,
                'date_to' => $filters['date_to'] ?? null,
            ],
        ];
    }

    private function summarize(
        Collection $tickets,
        array $statuses,
        array $priorities,
        array $agentNames,
        array $groupNames,
        array $onHoldStatusIds,
        array $overallSummary,
        int $ticketTotal,
        bool $truncated,
    ): array {
        $now = CarbonImmutable::now(config('app.timezone'));
        $today = $now->toDateString();
        $normalized = $tickets->map(function (array $ticket) use ($statuses, $priorities, $agentNames, $groupNames, $onHoldStatusIds, $today, $now) {
            $statusId = (int) ($ticket['status'] ?? 0);
            $status = $statuses[$statusId] ?? "Status {$statusId}";
            $priorityId = (int) ($ticket['priority'] ?? 0);
            $dueAt = filled($ticket['due_by'] ?? null)
                ? CarbonImmutable::parse($ticket['due_by'])->setTimezone(config('app.timezone'))
                : null;
            $createdAt = filled($ticket['created_at'] ?? null)
                ? CarbonImmutable::parse($ticket['created_at'])->setTimezone(config('app.timezone'))
                : null;
            $unresolved = ! in_array(strtolower($status), ['resolved', 'closed'], true)
                && ! in_array($statusId, [4, 5], true);
            $onHold = $unresolved && in_array($statusId, $onHoldStatusIds, true);
            $overdue = $unresolved && ! $onHold && $dueAt?->toDateString() < $today;
            $slaBreached = $unresolved && ! $onHold && (
                (bool) ($ticket['is_escalated'] ?? false)
                || (bool) ($ticket['fr_escalated'] ?? false)
            );
            $agentId = $ticket['responder_id'] ?? null;
            $groupId = $ticket['group_id'] ?? null;

            return [
                'id' => $ticket['id'] ?? null,
                'subject' => str($ticket['subject'] ?? 'No subject')->limit(90)->toString(),
                'type' => filled($ticket['type'] ?? null) ? (string) $ticket['type'] : 'Uncategorised',
                'category' => filled($ticket['category'] ?? null) ? (string) $ticket['category'] : 'Uncategorised',
                'sub_category' => filled($ticket['sub_category'] ?? null) ? (string) $ticket['sub_category'] : 'None',
                'item' => filled($ticket['item_category'] ?? null) ? (string) $ticket['item_category'] : 'None',
                'status' => $status,
                'priority' => $priorities[$priorityId] ?? "Priority {$priorityId}",
                'priority_id' => $priorityId,
                'unresolved' => $unresolved,
                'on_hold' => $onHold,
                'overdue' => $overdue,
                'due_today' => $unresolved && ! $onHold && $dueAt?->toDateString() === $today,
                'sla_breached' => $slaBreached,
                'pending_days' => $createdAt ? max(0, (int) $createdAt->diffInDays($now)) : 0,
                'created_at' => $createdAt?->toDateString(),
                'agent' => $agentId ? ($agentNames[(int) $agentId] ?? "Agent #{$agentId}") : 'Unassigned',
                'group' => $groupId ? ($groupNames[(int) $groupId] ?? "Group #{$groupId}") : 'No group',
            ];
        });
        $unresolved = $normalized->where('unresolved', true);
        $countBy = fn (Collection $items, string $key) => $items
            ->countBy($key)
            ->sortDesc()
            ->map(fn (int $value, string $label) => compact('label', 'value'))
            ->values()
            ->all();

        return [
            'summary' => [
                'total' => $ticketTotal,
                'open' => $normalized->filter(fn (array $ticket) => strcasecmp($ticket['status'], 'Open') === 0)->count(),
                'on_hold' => $unresolved->where('on_hold', true)->count(),
                'overdue' => $unresolved->where('overdue', true)->count(),
                'due_today' => $unresolved->where('due_today', true)->count(),
                'unassigned' => $unresolved->where('agent', 'Unassigned')->count(),
                'unresolved' => $unresolved->count(),
                'sla_breached' => $unresolved->where('sla_breached', true)->count(),
            ],
            'overall_ticket_summary' => $overallSummary,
            'unresolved_by_priority' => $countBy($unresolved, 'priority'),
            'unresolved_by_status' => $countBy($unresolved, 'status'),
            'unresolved_by_agent' => $countBy($unresolved, 'agent'),
            'unresolved_by_group' => $countBy($unresolved, 'group'),
            'unresolved_by_type' => $countBy($unresolved, 'type'),
            'unresolved_by_category' => $countBy($unresolved, 'category'),
            'critical_tickets' => $this->criticalTickets($unresolved),
            'agent_status_matrix' => $this->agentStatusMatrix($unresolved),
            'sla_breached_detail' => $this->slaBreachedDetail($unresolved),
            'sla_by_category' => $this->slaByCategory($unresolved),
            'ageing_bands' => $this->ageingBands($unresolved),
            'sla_breached_by_group_agent' => $unresolved
                ->where('sla_breached', true)
                ->groupBy(fn (array $ticket) => $ticket['group'].'|'.$ticket['agent'])
                ->map(function (Collection $items, string $key) {
                    [$group, $agent] = explode('|', $key, 2);

                    return ['group' => $group, 'agent' => $agent, 'value' => $items->count()];
                })
                ->sortByDesc('value')
                ->values()
                ->take(50)
                ->all(),
            'meta' => [
                'analyzed_tickets' => $normalized->count(),
                'unresolved_ticket_limit_reached' => $truncated,
                'timezone' => config('app.timezone'),
                'generated_at' => $now->toIso8601String(),
                'report_date' => $now->format('d-m-Y'),
            ],
        ];
    }

    /**
     * Unresolved Urgent/High tickets with full context for immediate action.
     */
    private function criticalTickets(Collection $unresolved): array
    {
        return $unresolved
            ->filter(fn (array $ticket) => in_array($ticket['priority_id'], [3, 4], true)
                || in_array(strtolower($ticket['priority']), ['urgent', 'high'], true))
            ->sortBy([
                ['priority_id', 'desc'],
                ['pending_days', 'desc'],
            ])
            ->map(fn (array $ticket) => [
                'id' => $ticket['id'],
                'group' => $ticket['group'],
                'priority' => $ticket['priority'],
                'agent' => $ticket['agent'],
                'status' => $ticket['status'],
                'subject' => $ticket['subject'],
                'pending_days' => $ticket['pending_days'],
            ])
            ->values()
            ->take(60)
            ->all();
    }

    /**
     * Agent x status pivot with a grand total per agent.
     */
    private function agentStatusMatrix(Collection $unresolved): array
    {
        $statusColumns = $unresolved
            ->pluck('status')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $rows = $unresolved
            ->groupBy('agent')
            ->map(function (Collection $items, string $agent) use ($statusColumns) {
                $counts = [];

                foreach ($statusColumns as $status) {
                    $count = $items->where('status', $status)->count();
                    $counts[$status] = $count > 0 ? $count : null;
                }

                return [
                    'agent' => $agent,
                    'counts' => $counts,
                    'total' => $items->count(),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();

        return [
            'columns' => $statusColumns,
            'rows' => $rows,
            'grand_total' => $unresolved->count(),
        ];
    }

    /**
     * Backlog ageing: how long unresolved tickets have been open, split by SLA state.
     */
    private function ageingBands(Collection $unresolved): array
    {
        $bands = [
            ['label' => '0-7 days', 'min' => 0, 'max' => 7],
            ['label' => '8-14 days', 'min' => 8, 'max' => 14],
            ['label' => '15-30 days', 'min' => 15, 'max' => 30],
            ['label' => '31-60 days', 'min' => 31, 'max' => 60],
            ['label' => '60+ days', 'min' => 61, 'max' => PHP_INT_MAX],
        ];

        $rows = collect($bands)->map(function (array $band) use ($unresolved) {
            $inBand = $unresolved->filter(fn (array $ticket) => $ticket['pending_days'] >= $band['min']
                && $ticket['pending_days'] <= $band['max']);
            $breached = $inBand->where('sla_breached', true)->count();

            return [
                'label' => $band['label'],
                'total' => $inBand->count(),
                'breached' => $breached,
                'within_sla' => $inBand->count() - $breached,
                'on_hold' => $inBand->where('on_hold', true)->count(),
            ];
        })->values()->all();

        $oldest = $unresolved->sortByDesc('pending_days')->take(10)->map(fn (array $ticket) => [
            'id' => $ticket['id'],
            'pending_days' => $ticket['pending_days'],
            'priority' => $ticket['priority'],
            'status' => $ticket['status'],
            'group' => $ticket['group'],
            'agent' => $ticket['agent'],
            'subject' => $ticket['subject'],
        ])->values()->all();

        return [
            'bands' => $rows,
            'oldest' => $oldest,
            'average_age' => $unresolved->isNotEmpty()
                ? round($unresolved->avg('pending_days'), 1)
                : 0.0,
            'median_age' => $unresolved->isNotEmpty()
                ? (int) $unresolved->pluck('pending_days')->sort()->values()->get(
                    (int) floor(($unresolved->count() - 1) / 2)
                )
                : 0,
        ];
    }

    /**
     * SLA exposure across the Category -> Sub Category -> Item hierarchy.
     *
     * Compliance is measured within the unresolved ticket population: a ticket
     * counts as compliant while it is still inside its SLA window.
     */
    private function slaByCategory(Collection $unresolved): array
    {
        $measure = function (Collection $items): array {
            $total = $items->count();
            $breached = $items->where('sla_breached', true)->count();

            return [
                'total' => $total,
                'breached' => $breached,
                'compliant' => $total - $breached,
                'compliance' => $total > 0 ? round((($total - $breached) / $total) * 100, 1) : 100.0,
            ];
        };

        $rows = $unresolved
            ->groupBy('category')
            ->map(fn (Collection $categoryItems, string $category) => [
                'category' => $category,
                ...$measure($categoryItems),
                'children' => $categoryItems
                    ->groupBy('sub_category')
                    ->map(fn (Collection $subItems, string $subCategory) => [
                        'category' => $category,
                        'sub_category' => $subCategory,
                        ...$measure($subItems),
                        'children' => $subItems
                            ->groupBy('item')
                            ->map(fn (Collection $itemItems, string $item) => [
                                'category' => $category,
                                'sub_category' => $subCategory,
                                'item' => $item,
                                ...$measure($itemItems),
                            ])
                            ->sortByDesc('breached')
                            ->values()
                            ->all(),
                    ])
                    ->sortByDesc('breached')
                    ->values()
                    ->all(),
            ])
            ->sortByDesc('breached')
            ->values()
            ->all();

        // Flat rows let the client filter and re-aggregate without another API call.
        $flat = $unresolved
            ->groupBy(fn (array $ticket) => $ticket['category'].'|'.$ticket['sub_category'].'|'.$ticket['item'])
            ->map(function (Collection $items, string $key) use ($measure) {
                [$category, $subCategory, $item] = explode('|', $key, 3);

                return [
                    'category' => $category,
                    'sub_category' => $subCategory,
                    'item' => $item,
                    ...$measure($items),
                ];
            })
            ->sortByDesc('breached')
            ->values()
            ->all();

        return [
            'hierarchy' => $rows,
            'flat' => $flat,
            'totals' => $measure($unresolved),
            'basis' => 'unresolved',
        ];
    }

    /**
     * SLA breached tickets grouped by group and agent, listing each ticket and its age.
     */
    private function slaBreachedDetail(Collection $unresolved): array
    {
        return $unresolved
            ->where('sla_breached', true)
            ->sortBy('group')
            ->groupBy('group')
            ->map(fn (Collection $groupItems, string $group) => [
                'group' => $group,
                'total' => $groupItems->count(),
                'agents' => $groupItems
                    ->sortByDesc('pending_days')
                    ->groupBy('agent')
                    ->map(fn (Collection $agentItems, string $agent) => [
                        'agent' => $agent,
                        'total' => $agentItems->count(),
                        'tickets' => $agentItems
                            ->sortByDesc('pending_days')
                            ->map(fn (array $ticket) => [
                                'id' => $ticket['id'],
                                'pending_days' => $ticket['pending_days'],
                                'priority' => $ticket['priority'],
                                'status' => $ticket['status'],
                                'subject' => $ticket['subject'],
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->sortByDesc('total')
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    private function ticketsByStatus(
        DataSource $source,
        string $url,
        array $statuses,
        array $filters,
    ): array {
        $tickets = collect();
        $summary = [];
        $total = 0;
        $truncated = false;
        $perPage = 100;
        $maxPages = config('integrations.freshservice.max_ticket_pages', 10);

        foreach ($statuses as $statusId => $statusLabel) {
            $query = 'status:'.$statusId.$this->dateClause($filters);

            $firstResponse = $this->get($source, $url, [
                'workspace_id' => 0,
                'query' => "\"{$query}\"",
                'page' => 1,
                'per_page' => $perPage,
            ]);
            $firstPage = $firstResponse->json('tickets', []);

            if (! is_array($firstPage)) {
                throw new RuntimeException('Freshservice returned an unexpected ticket response format.');
            }

            $statusTotal = (int) $firstResponse->json('total', count($firstPage));
            $summary[] = ['label' => $statusLabel, 'value' => $statusTotal];
            $total += $statusTotal;

            if (! $this->isUnresolvedStatus((int) $statusId, $statusLabel)) {
                continue;
            }

            $tickets->push(...array_filter($firstPage, 'is_array'));
            $pagesNeeded = min($maxPages, (int) ceil($statusTotal / $perPage));

            for ($page = 2; $page <= $pagesNeeded; $page++) {
                $response = $this->get($source, $url, [
                    'workspace_id' => 0,
                    'query' => "\"{$query}\"",
                    'page' => $page,
                    'per_page' => $perPage,
                ]);
                $pageRecords = $response->json('tickets', []);

                if (! is_array($pageRecords)) {
                    throw new RuntimeException('Freshservice returned an unexpected ticket response format.');
                }

                $tickets->push(...array_filter($pageRecords, 'is_array'));
            }

            $truncated = $truncated || $statusTotal > $maxPages * $perPage;
        }

        return [
            $tickets,
            collect($summary)->sortByDesc('value')->values()->all(),
            $total,
            $truncated,
        ];
    }

    private function isUnresolvedStatus(int $statusId, string $label): bool
    {
        return ! in_array(strtolower($label), ['resolved', 'closed'], true)
            && ! in_array($statusId, [4, 5], true);
    }

    /**
     * Build the created_at clause for a Freshservice filter query.
     *
     * Freshservice's `>` and `<` date comparisons are EXCLUSIVE of the given
     * day. Comparing against date_from directly therefore drops every ticket
     * created on date_from — so asking for a single day (date_from = date_to =
     * today, the natural way to express "today") returned zero. The bound is
     * shifted one day outward on each side to make the range inclusive.
     */
    private function dateClause(array $filters): string
    {
        $timezone = config('app.timezone');
        $clause = '';

        if (filled($filters['date_from'] ?? null)) {
            $dayBefore = CarbonImmutable::parse($filters['date_from'], $timezone)
                ->subDay()
                ->toDateString();
            $clause .= " AND created_at:>'{$dayBefore}'";
        }

        if (filled($filters['date_to'] ?? null)) {
            $dayAfter = CarbonImmutable::parse($filters['date_to'], $timezone)
                ->addDay()
                ->toDateString();
            $clause .= " AND created_at:<'{$dayAfter}'";
        }

        return $clause;
    }

    /**
     * Ticket totals per type across ALL tickets in the window.
     *
     * `unresolved_by_type` only covers the open backlog, so it cannot answer
     * "how many service requests were created today" — a request raised and
     * closed the same day would be missing. Rather than downloading resolved
     * tickets, this asks the filter endpoint for a count per type and reads only
     * the `total`, which is one cheap request per type.
     */
    private function ticketTotalsByType(
        DataSource $source,
        string $url,
        array $types,
        array $filters,
    ): array {
        $dateClause = $this->dateClause($filters);
        $rows = [];

        foreach ($types as $type) {
            // Escape single quotes so a custom type name cannot break the query.
            $safeType = str_replace("'", '', $type);
            $query = "type:'{$safeType}'".$dateClause;

            try {
                $response = $this->get($source, $url, [
                    'workspace_id' => 0,
                    'query' => "\"{$query}\"",
                    'page' => 1,
                    'per_page' => 1,
                ]);
            } catch (RuntimeException) {
                // A single unsupported type must not fail the whole report.
                continue;
            }

            $rows[] = [
                'label' => $type,
                'value' => (int) $response->json('total', 0),
            ];
        }

        return collect($rows)->sortByDesc('value')->values()->all();
    }

    /**
     * Ticket type names, from the form field definition where available.
     */
    private function typeChoices(array $fields): array
    {
        $field = collect($fields)->first(fn (array $field) => ($field['name'] ?? null) === 'ticket_type');
        $choices = $field['choices'] ?? [];
        $names = [];

        foreach ($choices as $label => $value) {
            // Freshservice returns type choices in several shapes depending on
            // the field configuration.
            if (is_string($label) && ! is_numeric($label)) {
                $names[] = $label;
            } elseif (is_string($value)) {
                $names[] = $value;
            }
        }

        return $names !== []
            ? array_values(array_unique($names))
            : ['Incident', 'Service Request'];
    }

    private function fieldChoices(array $fields, string $name, array $fallback): array
    {
        $field = collect($fields)->first(fn (array $field) => ($field['name'] ?? null) === $name);
        $choices = $field['choices'] ?? [];
        $normalized = [];

        foreach ($choices as $label => $value) {
            if (is_array($value) && is_numeric($value['id'] ?? null) && filled($value['value'] ?? null)) {
                $normalized[(int) $value['id']] = (string) $value['value'];
            } elseif (is_numeric($value)) {
                $normalized[(int) $value] = (string) $label;
            } elseif (is_numeric($label)) {
                $normalized[(int) $label] = (string) $value;
            }
        }

        return $normalized ?: $fallback;
    }

    private function loadCachedNames(int $sourceId, string $entityType): array
    {
        try {
            return DB::table('freshservice_directory_cache')
                ->where('data_source_id', $sourceId)
                ->where('entity_type', $entityType)
                ->pluck('name', 'entity_id')
                ->mapWithKeys(fn ($name, $id) => [(int) $id => $name])
                ->all();
        } catch (\Exception) {
            // Cache table doesn't exist yet (migration not run)
            return [];
        }
    }

    private function namesById(Collection $records, string $kind): array
    {
        return $records->mapWithKeys(function (array $record) use ($kind) {
            $id = $record['id'] ?? null;
            $name = $record['name']
                ?? trim(($record['first_name'] ?? '').' '.($record['last_name'] ?? ''))
                ?: ($record['contact']['name'] ?? null);

            return $id ? [(int) $id => $name ?: ucfirst($kind)." #{$id}"] : [];
        })->all();
    }

    private function paginated(
        DataSource $source,
        string $url,
        array $parameters,
        string $key,
        int $maxPages,
    ): Collection {
        $records = collect();
        $perPage = 100;

        for ($page = 1; $page <= $maxPages; $page++) {
            $response = $this->get($source, $url, [
                ...$parameters,
                'page' => $page,
                'per_page' => $perPage,
            ]);
            $pageRecords = $response->json($key, []);

            if (! is_array($pageRecords)) {
                throw new RuntimeException('Freshservice returned an unexpected response format.');
            }

            $records->push(...array_filter($pageRecords, 'is_array'));

            if (count($pageRecords) < $perPage) {
                break;
            }
        }

        return $records;
    }

    private function get(DataSource $source, string $url, array $parameters = []): Response
    {
        $this->urlGuard->assertAllowed($url);

        try {
            $response = $this->requests->make($source->apiConfiguration)->get($url, $parameters);
        } catch (ConnectionException) {
            throw new RuntimeException('Freshservice could not be reached from the application server.');
        }

        if (! $response->successful()) {
            throw new RuntimeException(match ($response->status()) {
                401, 403 => 'Freshservice rejected the configured API credentials or agent permissions.',
                429 => 'Freshservice rate-limited the analytics request. Try again shortly.',
                default => 'Freshservice could not provide ticket analytics.',
            });
        }

        if (strlen($response->body()) > config('integrations.freshservice.max_response_bytes', 5_000_000)) {
            throw new RuntimeException('Freshservice returned more data than the dashboard safety limit.');
        }

        return $response;
    }
}
