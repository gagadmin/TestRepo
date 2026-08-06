<?php

namespace App\Services\Seo\Analyzers;

use App\Models\DataSource;
use App\Models\SeoSnapshot;
use Illuminate\Support\Carbon;

/**
 * Period-over-period ranking movement from stored snapshots: which keywords are
 * declining, which are gaining, and the property's position/traffic series over
 * time. Degrades gracefully when there is not yet enough history.
 *
 * Position is "lower is better", so a POSITIVE delta (now − baseline) means a
 * keyword has WORSENED.
 */
class RankingTrendAnalyzer
{
    /**
     * @return array{
     *   available: bool,
     *   reason?: string,
     *   latest_on: ?string,
     *   baseline_on: ?string,
     *   declining: array<int, array<string, mixed>>,
     *   gaining: array<int, array<string, mixed>>,
     *   monitoring: array<int, array<string, mixed>>,
     *   trend_map: array<string, float>
     * }
     */
    public function analyze(DataSource $source, string $dimension = 'query'): array
    {
        $snapshots = SeoSnapshot::query()
            ->where('data_source_id', $source->id)
            ->where('dimension', $dimension)
            ->orderByDesc('captured_on')
            ->limit(60)
            ->get();

        $empty = [
            'available' => false,
            'reason' => 'Collecting data — trends appear once at least two snapshots exist.',
            'latest_on' => null,
            'baseline_on' => null,
            'declining' => [],
            'gaining' => [],
            'monitoring' => [],
            'trend_map' => [],
        ];

        if ($snapshots->isEmpty()) {
            return $empty;
        }

        $monitoring = $snapshots
            ->sortBy('captured_on')
            ->map(fn (SeoSnapshot $s) => [
                'captured_on' => $s->captured_on?->toDateString(),
                'position' => (float) ($s->summary['position'] ?? 0),
                'clicks' => (int) ($s->summary['clicks'] ?? 0),
                'impressions' => (int) ($s->summary['impressions'] ?? 0),
            ])
            ->values()
            ->all();

        $latest = $snapshots->first();
        $gapDays = (int) config('seo.comparison_gap_days', 28);
        $cutoff = Carbon::parse($latest->captured_on)->subDays($gapDays)->toDateString();

        // Most recent snapshot at least `gapDays` older than the latest.
        $baseline = $snapshots->firstWhere(
            fn (SeoSnapshot $s) => $s->captured_on?->toDateString() <= $cutoff
        );

        if (! $baseline) {
            return [
                ...$empty,
                'available' => true,
                'reason' => 'Not enough history yet for a period-over-period comparison.',
                'latest_on' => $latest->captured_on?->toDateString(),
                'monitoring' => $monitoring,
            ];
        }

        $latestRows = $this->keyedRows($latest);
        $baselineRows = $this->keyedRows($baseline);

        $threshold = (float) config('seo.decline_threshold', 1.5);
        $minImpressions = (int) config('seo.min_impressions', 50);

        $declining = [];
        $gaining = [];
        $trendMap = [];

        foreach ($latestRows as $key => $now) {
            if (! isset($baselineRows[$key])) {
                continue;
            }

            $prev = $baselineRows[$key];
            $delta = round($now['position'] - $prev['position'], 2); // + = worse
            $trendMap[$key] = $this->normalizeTrend($delta, $threshold);

            if ((int) $prev['impressions'] < $minImpressions) {
                continue;
            }

            $movement = [
                'keyword' => $key,
                'position' => $now['position'],
                'previous_position' => $prev['position'],
                'delta' => $delta,
                'impressions' => $now['impressions'],
                'previous_impressions' => $prev['impressions'],
            ];

            if ($delta >= $threshold) {
                $declining[] = $movement;
            } elseif ($delta <= -$threshold) {
                $gaining[] = $movement;
            }
        }

        usort($declining, fn ($a, $b) => $b['delta'] <=> $a['delta']);
        usort($gaining, fn ($a, $b) => $a['delta'] <=> $b['delta']);

        return [
            'available' => true,
            'latest_on' => $latest->captured_on?->toDateString(),
            'baseline_on' => $baseline->captured_on?->toDateString(),
            'declining' => $declining,
            'gaining' => $gaining,
            'monitoring' => $monitoring,
            'trend_map' => $trendMap,
        ];
    }

    /**
     * @return array<string, array{position: float, impressions: int, clicks: int, ctr: float}>
     */
    private function keyedRows(SeoSnapshot $snapshot): array
    {
        return $snapshot->rows()
            ->get(['key', 'position', 'impressions', 'clicks', 'ctr'])
            ->keyBy('key')
            ->map(fn ($row) => [
                'position' => (float) $row->position,
                'impressions' => (int) $row->impressions,
                'clicks' => (int) $row->clicks,
                'ctr' => (float) $row->ctr,
            ])
            ->all();
    }

    /** Improvement (negative delta) → positive trend, clamped to −1..1. */
    private function normalizeTrend(float $delta, float $threshold): float
    {
        $threshold = max(0.01, $threshold);

        return max(-1.0, min(1.0, -$delta / $threshold));
    }
}
