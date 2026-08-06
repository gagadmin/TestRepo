<?php

namespace App\Services\Reporting;

use App\Models\DataSource;
use App\Models\Report;
use App\Models\ReportSnapshot;
use App\Models\User;
use App\Services\Ai\ReportingDataGateway;
use App\Services\Integrations\FreshserviceAnalyticsService;
use Illuminate\Support\Arr;
use RuntimeException;

class ReportDataService
{
    public function __construct(
        private readonly ReportingDataGateway $gateway,
        private readonly FreshserviceAnalyticsService $freshservice,
    ) {}

    public function generate(Report $report, User $user, array $filters = []): ReportSnapshot
    {
        $sourceId = data_get($report->definition, 'source_id');

        if (! $sourceId) {
            throw new RuntimeException('Assign a connected data source before generating this report.');
        }

        $source = DataSource::query()->with('apiConfiguration')->findOrFail($sourceId);

        if (! $source->isAccessibleBy($user)) {
            throw new RuntimeException('You are not authorized to use the assigned data source.');
        }

        if ($source->status !== 'connected') {
            throw new RuntimeException('The assigned data source must pass its connection test first.');
        }

        if ($report->type === 'itsm_ticket_summary' && $source->type === 'freshservice') {
            return $this->generateFreshserviceSnapshot($report, $user, $source, $filters);
        }

        $result = $this->gateway->fetch($source, [
            'report_type' => $report->type,
            'dimension' => $source->type === 'google_search_console'
                ? data_get($report->definition, 'search_console_dimension', 'query')
                : null,
            ...Arr::only($filters, ['date_from', 'date_to', 'department', 'region', 'status']),
        ]);
        $rows = $this->normaliseRows($result->data, $report->definition['columns'] ?? []);
        $snapshot = $report->snapshots()->create([
            'generated_by' => $user->id,
            'data' => $rows,
            'summary' => [
                ...$result->summary,
                'filters' => $filters,
                'numeric_totals' => $this->numericTotals($rows),
            ],
            'citations' => $result->citations,
            'row_count' => count($rows),
            'generated_at' => now(),
        ]);

        $report->update(['last_generated_at' => $snapshot->generated_at]);

        return $snapshot;
    }

    private function generateFreshserviceSnapshot(
        Report $report,
        User $user,
        DataSource $source,
        array $filters,
    ): ReportSnapshot {
        $analytics = $this->freshservice->analytics(
            $source,
            Arr::only($filters, ['date_from', 'date_to']),
        );
        $rawRows = collect()
            ->push(...$this->metricRows('Current ticket summary', $analytics['summary'] ?? []))
            ->push(...$this->labelValueRows('Overall ticket summary', $analytics['overall_ticket_summary'] ?? []))
            ->push(...$this->labelValueRows('Unresolved by type', $analytics['unresolved_by_type'] ?? []))
            ->push(...$this->labelValueRows('Unresolved by priority', $analytics['unresolved_by_priority'] ?? []))
            ->push(...$this->labelValueRows('Unresolved by status', $analytics['unresolved_by_status'] ?? []))
            ->push(...$this->labelValueRows('Unresolved by group', $analytics['unresolved_by_group'] ?? []))
            ->push(...collect($analytics['critical_tickets'] ?? [])->map(fn (array $row) => [
                'section' => 'Unresolved P1 and P2 tickets',
                'metric' => '#'.($row['id'] ?? '').' · '.(string) ($row['priority'] ?? ''),
                'detail' => trim(sprintf(
                    '%s · %s · %s · %s',
                    $row['group'] ?? 'No group',
                    $row['agent'] ?? 'Unassigned',
                    $row['status'] ?? '',
                    $row['subject'] ?? '',
                )),
                'count' => (int) ($row['pending_days'] ?? 0),
            ])->all())
            ->push(...$this->labelValueRows('All unresolved tickets by agent', $analytics['unresolved_by_agent'] ?? []))
            ->push(...collect($analytics['sla_breached_detail'] ?? [])
                ->flatMap(fn (array $group) => collect($group['agents'] ?? [])
                    ->flatMap(fn (array $agent) => collect($agent['tickets'] ?? [])
                        ->map(fn (array $ticket) => [
                            'section' => 'SLA breached tickets by group and agent',
                            'metric' => (string) ($group['group'] ?? 'No group'),
                            'detail' => ($agent['agent'] ?? 'Unassigned').' · #'.($ticket['id'] ?? ''),
                            'count' => (int) ($ticket['pending_days'] ?? 0),
                        ])))
                ->all())
            ->values()
            ->all();
        $rows = $this->normaliseRows(
            ['rows' => $rawRows],
            $report->definition['columns'] ?? [],
        );
        $snapshot = $report->snapshots()->create([
            'generated_by' => $user->id,
            'data' => $rows,
            'summary' => [
                'filters' => $filters,
                'numeric_totals' => $this->numericTotals($rows),
                'itsm' => $analytics,
            ],
            'citations' => [[
                'source_id' => $source->id,
                'source_name' => $source->name,
                'source_type' => $source->type,
                'retrieved_at' => now()->toIso8601String(),
            ]],
            'row_count' => count($rows),
            'generated_at' => now(),
        ]);

        $report->update(['last_generated_at' => $snapshot->generated_at]);

        return $snapshot;
    }

    private function metricRows(string $section, array $metrics): array
    {
        return collect($metrics)->map(fn (mixed $value, string $label) => [
            'section' => $section,
            'metric' => str($label)->replace('_', ' ')->title()->toString(),
            'detail' => '',
            'count' => is_numeric($value) ? (int) $value : 0,
        ])->values()->all();
    }

    private function labelValueRows(string $section, array $items): array
    {
        return collect($items)->map(fn (array $item) => [
            'section' => $section,
            'metric' => (string) ($item['label'] ?? 'Unknown'),
            'detail' => '',
            'count' => (int) ($item['value'] ?? 0),
        ])->all();
    }

    public function filteredRows(?ReportSnapshot $snapshot, array $filters, ?int $limit = null): array
    {
        if (! $snapshot) {
            return [];
        }

        $rows = array_values(array_filter($snapshot->data, function (array $row) use ($filters) {
            foreach (Arr::only($filters, ['department', 'region', 'status']) as $key => $value) {
                if ($value !== null && $value !== '' && strcasecmp((string) ($row[$key] ?? ''), (string) $value) !== 0) {
                    return false;
                }
            }

            $dateKey = $this->dateKey($row);
            $date = $dateKey ? strtotime((string) $row[$dateKey]) : false;

            if ($date && ! empty($filters['date_from']) && $date < strtotime($filters['date_from'])) {
                return false;
            }

            if ($date && ! empty($filters['date_to']) && $date > strtotime($filters['date_to'].' 23:59:59')) {
                return false;
            }

            return true;
        }));

        return $limit === null ? $rows : array_slice($rows, 0, $limit);
    }

    private function normaliseRows(array $data, array $columns): array
    {
        $rows = $data['rows'] ?? $data['data'] ?? $data;

        if (! is_array($rows)) {
            return [];
        }

        if (! array_is_list($rows)) {
            $rows = [$rows];
        }

        $allowedColumns = collect($columns)->keyBy('key');

        return collect($rows)
            ->filter(fn ($row) => is_array($row))
            ->map(function (array $row) use ($allowedColumns) {
                return $allowedColumns->mapWithKeys(function (array $column, string $key) use ($row) {
                    $value = data_get($row, $key);
                    $value = is_scalar($value) || $value === null ? $value : json_encode($value);

                    return [$key => $this->maskValue($key, $value, $column['mask'] ?? null)];
                })->all();
            })
            ->take(config('reporting.max_snapshot_rows'))
            ->values()
            ->all();
    }

    private function maskValue(string $key, mixed $value, ?string $mask): mixed
    {
        if (preg_match('/(?:password|passwd|secret|token|api[_-]?key|private[_-]?key|ssn|national[_-]?id|passport|credit[_-]?card|card[_-]?number|cvv|iban|bank[_-]?account)/i', $key)) {
            return '[REDACTED]';
        }

        if ($value === null || $mask === null) {
            return $value;
        }

        $text = (string) $value;

        return match ($mask) {
            'email' => preg_replace('/^(.)([^@]*)(@.*)$/', '$1***$3', $text),
            'phone', 'last4' => str_repeat('*', max(0, strlen($text) - 4)).substr($text, -4),
            'redact' => '[REDACTED]',
            default => $value,
        };
    }

    private function numericTotals(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            foreach ($row as $key => $value) {
                if (is_int($value) || is_float($value)) {
                    $totals[$key] = ($totals[$key] ?? 0) + $value;
                }
            }
        }

        return $totals;
    }

    private function dateKey(array $row): ?string
    {
        foreach (['date', 'created_at', 'period', 'month'] as $key) {
            if (array_key_exists($key, $row)) {
                return $key;
            }
        }

        return null;
    }
}
