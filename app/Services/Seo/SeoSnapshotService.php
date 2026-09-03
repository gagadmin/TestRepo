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
            $attributes = [
                'site_url' => data_get($source->settings, 'site_url'),
                'window_from' => $windowFrom,
                'window_to' => $windowTo,
                'summary' => $summary,
            ];

            /*
             * The lookup uses whereDate rather than updateOrCreate on the raw
             * value. `captured_on` has a `date` cast, so it is written as
             * "Y-m-d 00:00:00" while the caller supplies "Y-m-d": on an engine
             * that compares the column as text (SQLite) the two never match, so
             * a re-capture inserted a second row and hit the unique index
             * instead of replacing the first. whereDate normalises both sides
             * on every supported engine.
             */
            $snapshot = SeoSnapshot::query()
                ->where('data_source_id', $source->id)
                ->where('dimension', $dimension)
                ->whereDate('captured_on', $windowTo)
                ->first();

            if ($snapshot) {
                $snapshot->update($attributes);
            } else {
                $snapshot = SeoSnapshot::create([
                    'data_source_id' => $source->id,
                    'captured_on' => $windowTo,
                    'dimension' => $dimension,
                    ...$attributes,
                ]);
            }

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
