<?php

namespace App\Services\Seo;

use App\Models\DataSource;
use App\Models\SeoProfile;
use App\Models\User;
use App\Services\Seo\Analyzers\CountryOpportunityAnalyzer;
use App\Services\Seo\Analyzers\CtrGapAnalyzer;
use App\Services\Seo\Analyzers\PositionOpportunityAnalyzer;
use App\Services\Seo\Analyzers\RankingTrendAnalyzer;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Orchestrates the deterministic Phase 1 SEO insights for one property: pulls
 * Search Console data, runs the analyzers, computes the health score, and
 * reports which sections are available.
 *
 * No AI here — every figure is computed from GSC data so results are
 * reproducible and testable. The AI action plan (Phase 3) consumes this output.
 */
class SeoInsightsService
{
    public function __construct(
        private readonly SearchConsoleGateway $gateway,
        private readonly PositionOpportunityAnalyzer $positions,
        private readonly CtrGapAnalyzer $ctrGaps,
        private readonly CountryOpportunityAnalyzer $countries,
        private readonly RankingTrendAnalyzer $trends,
        private readonly SeoHealthScore $health,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forSource(DataSource $source, User $user, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $ttl = (int) config('seo.cache_seconds', 900);
        // The profile's version is part of the key so saving categories/regions
        // busts the cache immediately — otherwise the reload after a save would
        // return the previous profile and the form would appear to revert.
        $profileVersion = SeoProfile::where('data_source_id', $source->id)
            ->value('updated_at');
        $key = $this->cacheKey($source, $user, $dateFrom, $dateTo, (string) $profileVersion);

        $build = fn () => $this->build($source, $dateFrom, $dateTo);

        return $ttl > 0 ? Cache::remember($key, $ttl, $build) : $build();
    }

    /**
     * @return array<string, mixed>
     */
    private function build(DataSource $source, ?string $dateFrom, ?string $dateTo): array
    {
        $profile = SeoProfile::where('data_source_id', $source->id)->first();

        try {
            $data = $this->gateway->pull($source, $dateFrom, $dateTo);
        } catch (Throwable $exception) {
            // A missing credential/property is an expected, reportable state —
            // surface it rather than 500, mirroring the connector-failure ethos.
            return [
                'available' => false,
                'reason' => $exception->getMessage(),
                'profile' => $this->profilePayload($profile),
                'sections' => $this->sections(false),
            ];
        }

        $summary = $data['query']['summary'] ?? [];
        $queryRows = $data['query']['rows'] ?? [];
        $pageRows = $data['page']['rows'] ?? [];
        $countryRows = $data['country']['rows'] ?? [];
        $topN = (int) config('seo.top_opportunities', 12);

        // Trends from stored snapshots (Phase 2). The trend map sharpens the
        // opportunity score; declining/gaining lists power the Trends tab.
        $trends = $this->trends->analyze($source, 'query');

        $positions = $this->positions->analyze($queryRows, $profile, $trends['trend_map'] ?? []);

        return [
            'available' => true,
            'window' => $data['window'],
            'summary' => $summary,
            'profile' => $this->profilePayload($profile),
            'health' => $this->health->compute($summary, $queryRows),
            'top_opportunities' => array_slice($positions, 0, $topN),
            'opportunities' => [
                'positions_6_20' => $positions,
                'ctr_gaps' => $this->ctrGaps->analyze($pageRows, 'page'),
                'countries' => $this->countries->analyze(
                    $countryRows,
                    (float) ($summary['position'] ?? 0),
                    $profile,
                ),
            ],
            'trends' => $trends,
            'sections' => $this->sections(true, (bool) ($trends['available'] ?? false)),
        ];
    }

    /**
     * Availability of each requested capability, so the UI never fakes a section.
     *
     * @return array<string, string>
     */
    private function sections(bool $gscOk, bool $trendsAvailable = false): array
    {
        return [
            'positions_6_20' => $gscOk ? 'available' : 'unavailable',
            'ctr_gaps' => $gscOk ? 'available' : 'unavailable',
            'countries' => $gscOk ? 'available' : 'unavailable',
            'health' => $gscOk ? 'available' : 'unavailable',
            // Available once enough snapshots exist; "collecting" until then.
            'declining_rankings' => $trendsAvailable ? 'available' : 'collecting',
            // Delivered in later phases.
            'technical_seo' => 'phase_4_web_research',
            'competitor_gaps' => 'phase_4_web_research',
            'backlink_targets' => 'phase_4_web_research',
            'ai_action_plan' => 'phase_3',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function profilePayload(?SeoProfile $profile): ?array
    {
        if (! $profile) {
            return null;
        }

        return [
            'categories' => $profile->categories ?? [],
            'regions' => $profile->regions ?? [],
            'competitor_seeds' => $profile->competitor_seeds ?? [],
            'brand_terms' => $profile->brand_terms ?? [],
            'updated_at' => $profile->updated_at?->toIso8601String(),
        ];
    }

    private function cacheKey(DataSource $source, User $user, ?string $from, ?string $to, string $profileVersion = ''): string
    {
        return 'seo.insights:'.hash('sha256', json_encode([
            'source' => $source->id,
            'from' => $from,
            'to' => $to,
            'profile_version' => $profileVersion,
            // Access scope, so a cached entry cannot cross a permission boundary.
            'scope' => [
                'roles' => $user->roles()->pluck('name')->sort()->values()->all(),
                'department' => $user->department,
            ],
        ]));
    }
}
