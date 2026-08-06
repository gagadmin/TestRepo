<?php

namespace App\Services\Seo;

/**
 * Turns a Search Console row into a transparent "closeness to Top 5" score.
 *
 * This is deliberately an opportunity score, NOT a probability of ranking or a
 * time-to-rank estimate — outcomes depend on competitors and Google's algorithm,
 * which are outside this data. Every component is returned alongside the score so
 * the UI and the AI can explain exactly why a keyword scored highly.
 */
class SeoOpportunityScorer
{
    /**
     * @param  array{position: float, impressions: int, ctr: float}  $row  ctr is a percentage (e.g. 3.2)
     * @param  float  $trend  −1..1 (Phase 2). 0 when no history is available.
     * @return array{score: int, components: array<string, float>, recoverable_clicks: int, expected_ctr: float}
     */
    public function score(array $row, float $trend = 0.0): array
    {
        $weights = (array) config('seo.scoring');
        $band = (array) config('seo.position_band');

        $position = (float) ($row['position'] ?? 0);
        $impressions = (int) ($row['impressions'] ?? 0);
        $actualCtr = (float) ($row['ctr'] ?? 0); // percent
        $expectedCtr = $this->expectedCtrPercent($position);

        // Proximity: 1.0 at the near edge of the band (pos 6), 0 at the far edge.
        $from = (float) ($band['from'] ?? 6);
        $to = (float) ($band['to'] ?? 20);
        $proximity = $this->clamp(($to - $position) / max(0.01, $to - $from), 0, 1);

        // Demand: log-scaled impressions, normalised against a reference.
        $reference = max(0.1, (float) config('seo.demand_log_reference', 4.0));
        $demand = $this->clamp(log10($impressions + 1) / $reference, 0, 1);

        // CTR headroom: how much of the expected CTR is currently unrealised.
        $ctrHeadroom = $expectedCtr > 0
            ? $this->clamp(($expectedCtr - $actualCtr) / $expectedCtr, 0, 1)
            : 0.0;

        // Trend normalised from −1..1 to 0..1 (0.5 = flat / unknown in Phase 1).
        $trendComponent = $this->clamp(($trend + 1) / 2, 0, 1);

        $components = [
            'proximity' => round($proximity, 3),
            'demand' => round($demand, 3),
            'ctr_headroom' => round($ctrHeadroom, 3),
            'trend' => round($trendComponent, 3),
        ];

        $score = ($weights['proximity'] ?? 0) * $proximity
            + ($weights['demand'] ?? 0) * $demand
            + ($weights['ctr_headroom'] ?? 0) * $ctrHeadroom
            + ($weights['trend'] ?? 0) * $trendComponent;

        $totalWeight = array_sum(array_map('floatval', $weights)) ?: 1.0;

        return [
            'score' => (int) round(($score / $totalWeight) * 100),
            'components' => $components,
            'recoverable_clicks' => (int) round($impressions * max(0, $expectedCtr - $actualCtr) / 100),
            'expected_ctr' => round($expectedCtr, 2),
        ];
    }

    /** Expected organic CTR for a position, as a percentage. */
    public function expectedCtrPercent(float $position): float
    {
        $curve = (array) config('seo.ctr_curve');
        $index = (int) round($position);
        $index = max(1, min(20, $index));
        $fraction = (float) ($curve[$index] ?? $curve[20] ?? 0.008);

        return $fraction * 100;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
