<?php

namespace App\Services\Seo\Analyzers;

use App\Models\SeoProfile;
use App\Services\Seo\SeoOpportunityScorer;

/**
 * Keywords sitting in positions 6–20 with real demand — the ones closest to the
 * Top 5. Deterministic: computed entirely from stored/pulled GSC rows.
 */
class PositionOpportunityAnalyzer
{
    public function __construct(private readonly SeoOpportunityScorer $scorer) {}

    /**
     * @param  array<int, array<string, mixed>>  $queryRows  GSC rows keyed by 'query'
     * @param  array<string, float>  $trendMap  keyword → normalized trend (−1..1); Phase 2
     * @return array<int, array<string, mixed>>
     */
    public function analyze(array $queryRows, ?SeoProfile $profile = null, array $trendMap = []): array
    {
        $band = (array) config('seo.position_band');
        $from = (float) ($band['from'] ?? 6);
        $to = (float) ($band['to'] ?? 20);
        $minImpressions = (int) config('seo.min_impressions', 50);
        $brandTerms = $profile?->normalizedBrandTerms() ?? [];

        return collect($queryRows)
            ->filter(function (array $row) use ($from, $to, $minImpressions) {
                $position = (float) ($row['position'] ?? 0);

                return $position >= $from
                    && $position <= $to
                    && (int) ($row['impressions'] ?? 0) >= $minImpressions;
            })
            ->map(function (array $row) use ($brandTerms, $trendMap) {
                $keyword = (string) ($row['query'] ?? '');
                $scored = $this->scorer->score($row, (float) ($trendMap[$keyword] ?? 0.0));

                return [
                    'keyword' => $keyword,
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'ctr' => (float) ($row['ctr'] ?? 0),
                    'position' => (float) ($row['position'] ?? 0),
                    'expected_ctr' => $scored['expected_ctr'],
                    'recoverable_clicks' => $scored['recoverable_clicks'],
                    'opportunity_score' => $scored['score'],
                    'score_components' => $scored['components'],
                    'is_brand' => $this->isBrand($keyword, $brandTerms),
                ];
            })
            ->sortByDesc('opportunity_score')
            ->values()
            ->all();
    }

    private function isBrand(string $keyword, array $brandTerms): bool
    {
        $keyword = strtolower($keyword);

        foreach ($brandTerms as $term) {
            if ($term !== '' && str_contains($keyword, $term)) {
                return true;
            }
        }

        return false;
    }
}
