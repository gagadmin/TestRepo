<?php

namespace App\Services\Seo\Analyzers;

use App\Services\Seo\SeoOpportunityScorer;

/**
 * Pages (or queries) that earn plenty of impressions but a below-expected CTR —
 * i.e. visible in search but under-clicked. Impact is the recoverable clicks if
 * CTR rose to the position's baseline.
 */
class CtrGapAnalyzer
{
    public function __construct(private readonly SeoOpportunityScorer $scorer) {}

    /**
     * @param  array<int, array<string, mixed>>  $rows  GSC rows keyed by $dimension
     * @return array<int, array<string, mixed>>
     */
    public function analyze(array $rows, string $dimension = 'page'): array
    {
        $minImpressions = (int) config('seo.min_impressions', 50);
        $tolerance = (float) config('seo.ctr_gap_tolerance', 0.4);

        return collect($rows)
            ->filter(fn (array $row) => (int) ($row['impressions'] ?? 0) >= $minImpressions)
            ->map(function (array $row) use ($dimension) {
                $expected = $this->scorer->expectedCtrPercent((float) ($row['position'] ?? 0));
                $actual = (float) ($row['ctr'] ?? 0);
                $impressions = (int) ($row['impressions'] ?? 0);

                return [
                    $dimension => (string) ($row[$dimension] ?? ''),
                    'impressions' => $impressions,
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'ctr' => $actual,
                    'expected_ctr' => round($expected, 2),
                    'position' => (float) ($row['position'] ?? 0),
                    'recoverable_clicks' => (int) round($impressions * max(0, $expected - $actual) / 100),
                ];
            })
            ->filter(fn (array $row) => $row['ctr'] < $row['expected_ctr'] * (1 - $tolerance))
            ->sortByDesc('recoverable_clicks')
            ->values()
            ->all();
    }
}
