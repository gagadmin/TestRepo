<?php

namespace App\Services\Seo;

/**
 * A composite 0–100 SEO health index from the signals available today (average
 * position, overall CTR vs. expectation, and the share of impressions coming
 * from page-one positions). Transparent: the breakdown is returned with it.
 */
class SeoHealthScore
{
    public function __construct(private readonly SeoOpportunityScorer $scorer) {}

    /**
     * @param  array<string, mixed>  $summary  GSC totals (clicks, impressions, ctr%, position)
     * @param  array<int, array<string, mixed>>  $queryRows
     * @return array{score: int, breakdown: array<string, float>}
     */
    public function compute(array $summary, array $queryRows): array
    {
        $avgPosition = (float) ($summary['position'] ?? 0);
        $overallCtr = (float) ($summary['ctr'] ?? 0);

        // Position: 1.0 at position 1, 0 at position 20+.
        $positionScore = $this->clamp((20 - $avgPosition) / 19, 0, 1);

        // CTR vs. the baseline expected at the average position.
        $expectedCtr = $this->scorer->expectedCtrPercent($avgPosition);
        $ctrScore = $expectedCtr > 0 ? $this->clamp($overallCtr / $expectedCtr, 0, 1) : 0.0;

        // Share of impressions from page-one (position ≤ 10).
        $totalImpressions = collect($queryRows)->sum(fn ($r) => (int) ($r['impressions'] ?? 0));
        $pageOneImpressions = collect($queryRows)
            ->filter(fn ($r) => (float) ($r['position'] ?? 99) <= 10)
            ->sum(fn ($r) => (int) ($r['impressions'] ?? 0));
        $pageOneShare = $totalImpressions > 0 ? $pageOneImpressions / $totalImpressions : 0.0;

        $breakdown = [
            'position' => round($positionScore, 3),
            'ctr' => round($ctrScore, 3),
            'page_one_share' => round($pageOneShare, 3),
        ];

        $score = 0.45 * $positionScore + 0.30 * $ctrScore + 0.25 * $pageOneShare;

        return [
            'score' => (int) round($score * 100),
            'breakdown' => $breakdown,
        ];
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
