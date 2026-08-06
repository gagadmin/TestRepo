<?php

namespace App\Services\Seo;

use App\Models\DataSource;
use App\Models\SeoSnapshot;
use App\Models\SeoSnapshotRow;
use Illuminate\Support\Facades\DB;

/**
 * Persists Search Console snapshots. Idempotent: re-capturing the same property,
 * day and dimension replaces that snapshot's rows rather than duplicating them,
 * so a re-run (or overlap) is safe.
 */
class SeoSnapshotService
{
    /**
     * @param  array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>}  $data
     */
    public function capture(DataSource $source, string $dimension, array $data, string $windowFrom, string $windowTo): SeoSnapshot
    {
        $rows = $data['rows'] ?? [];
        $summary = $data['summary'] ?? [];

        return DB::transaction(function () use ($source, $dimension, $rows, $summary, $windowFrom, $windowTo) {
            $snapshot = SeoSnapshot::updateOrCreate(
                [
                    'data_source_id' => $source->id,
                    'captured_on' => $windowTo,
                    'dimension' => $dimension,
                ],
                [
                    'site_url' => data_get($source->settings, 'site_url'),
                    'window_from' => $windowFrom,
                    'window_to' => $windowTo,
                    'summary' => $summary,
                ],
            );

            // Replace rows for idempotency.
            $snapshot->rows()->delete();

            $now = now();
            $payload = collect($rows)
                ->map(fn (array $row) => [
                    'seo_snapshot_id' => $snapshot->id,
                    'key' => mb_substr((string) ($row[$dimension] ?? ''), 0, 512),
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'ctr' => (float) ($row['ctr'] ?? 0),
                    'position' => (float) ($row['position'] ?? 0),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->filter(fn (array $row) => $row['key'] !== '')
                ->all();

            foreach (array_chunk($payload, 500) as $chunk) {
                SeoSnapshotRow::insert($chunk);
            }

            return $snapshot;
        });
    }

    /** Remove snapshots older than the retention window. Returns rows deleted. */
    public function prune(int $retentionDays): int
    {
        return SeoSnapshot::query()
            ->where('captured_on', '<', now()->subDays($retentionDays)->toDateString())
            ->delete();
    }
}
