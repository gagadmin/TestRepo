<?php

namespace App\Services\Seo\Analyzers;

use App\Models\SeoProfile;

/**
 * Countries where demand exists (impressions) but capture is weak (low CTR or
 * poor average position) — i.e. market-expansion candidates. Target regions from
 * the profile are surfaced first.
 */
class CountryOpportunityAnalyzer
{
    /**
     * @param  array<int, array<string, mixed>>  $countryRows  GSC rows keyed by 'country' (ISO-3)
     * @param  float  $siteAveragePosition  Overall property average position
     * @return array<int, array<string, mixed>>
     */
    public function analyze(array $countryRows, float $siteAveragePosition, ?SeoProfile $profile = null): array
    {
        $minImpressions = (int) config('seo.min_country_impressions', 100);
        $targetCodes = collect($profile?->regions ?? [])
            ->map(fn ($region) => strtolower((string) ($region['code'] ?? $region['name'] ?? '')))
            ->filter()
            ->all();

        return collect($countryRows)
            ->filter(fn (array $row) => (int) ($row['impressions'] ?? 0) >= $minImpressions)
            ->map(function (array $row) use ($siteAveragePosition, $targetCodes) {
                $code = (string) ($row['country'] ?? '');
                $position = (float) ($row['position'] ?? 0);
                $ctr = (float) ($row['ctr'] ?? 0);
                $weakCapture = $position > $siteAveragePosition || $ctr < 1.0;

                return [
                    'country' => $code,
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'ctr' => $ctr,
                    'position' => $position,
                    'is_target_region' => in_array(strtolower($code), $targetCodes, true),
                    'weak_capture' => $weakCapture,
                    'reason' => $weakCapture
                        ? 'Demand present but capture is weak (position or CTR below site average).'
                        : 'Healthy demand and capture.',
                ];
            })
            // Weak-capture markets first, then by impressions.
            ->sortBy([['weak_capture', 'desc'], ['impressions', 'desc']])
            ->values()
            ->all();
    }
}
