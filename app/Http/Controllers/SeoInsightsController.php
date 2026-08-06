<?php

namespace App\Http\Controllers;

use App\Http\Requests\SeoProfileRequest;
use App\Models\DataSource;
use App\Models\SeoActionPlan;
use App\Models\SeoProfile;
use App\Models\SeoResearchSnapshot;
use App\Services\Seo\SeoInsightAssistant;
use App\Services\Seo\SeoInsightsService;
use App\Services\Seo\SeoWebResearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SEO insights: deterministic Search Console analysis plus per-property category
 * and region profiles. Gated on `seo.view`; each property is additionally
 * checked for the caller's visibility, so SEO data respects the same access
 * rules as reporting.
 */
class SeoInsightsController extends Controller
{
    public function __construct(private readonly SeoInsightsService $insights) {}

    /** Connected Search Console properties the user may read, with their profiles. */
    public function index(Request $request): JsonResponse
    {
        $sources = DataSource::query()
            ->where('type', 'google_search_console')
            ->where('status', 'connected')
            ->orderBy('name')
            ->get()
            ->filter(fn (DataSource $source) => $source->isAccessibleBy($request->user()))
            ->map(fn (DataSource $source) => [
                'id' => $source->id,
                'name' => $source->name,
                'site_url' => data_get($source->settings, 'site_url'),
                'has_profile' => SeoProfile::where('data_source_id', $source->id)->exists(),
            ])
            ->values();

        return response()->json(['data' => $sources]);
    }

    /** Full deterministic insight set for one property. */
    public function show(Request $request, DataSource $dataSource): JsonResponse
    {
        $this->authorizeSource($request, $dataSource);

        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        return response()->json([
            'data' => $this->insights->forSource(
                $dataSource,
                $request->user(),
                $validated['date_from'] ?? null,
                $validated['date_to'] ?? null,
            ),
        ]);
    }

    /** Create or update the property's category/region profile. */
    public function saveProfile(SeoProfileRequest $request, DataSource $dataSource): JsonResponse
    {
        $this->authorizeSource($request, $dataSource);

        $profile = SeoProfile::updateOrCreate(
            ['data_source_id' => $dataSource->id],
            [
                'categories' => $request->validated()['categories'] ?? [],
                'regions' => $request->validated()['regions'] ?? [],
                'brand_terms' => $request->validated()['brand_terms'] ?? [],
                'competitor_seeds' => $request->validated()['competitor_seeds'] ?? [],
                'updated_by' => $request->user()->id,
            ],
        );

        return response()->json([
            'message' => 'SEO profile saved.',
            'data' => [
                'categories' => $profile->categories,
                'regions' => $profile->regions,
                'brand_terms' => $profile->brand_terms,
                'competitor_seeds' => $profile->competitor_seeds,
                'updated_at' => $profile->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /** Generate a fresh AI action plan from the current deterministic insights. */
    public function generateActionPlan(Request $request, DataSource $dataSource, SeoInsightAssistant $assistant): JsonResponse
    {
        abort_unless($request->user()->hasPermission('seo.generate'), 403);
        $this->authorizeSource($request, $dataSource);

        $insights = $this->insights->forSource($dataSource, $request->user());

        abort_if(
            ! ($insights['available'] ?? false),
            422,
            'Search Console data is not available for this property, so no plan can be generated.',
        );

        // Fold in the most recent web-research findings (Phase 4), if any, so
        // competitor/backlink/technical actions become concrete and cited.
        $research = SeoResearchSnapshot::where('data_source_id', $dataSource->id)
            ->latest()
            ->first()?->findings;

        $plan = $assistant->generate($dataSource, $request->user(), $insights, $research);

        return response()->json([
            'message' => 'Action plan generated.',
            'data' => $this->serializePlan($plan),
        ], 201);
    }

    /** Most recent stored action plans for the property. */
    public function actionPlans(Request $request, DataSource $dataSource): JsonResponse
    {
        $this->authorizeSource($request, $dataSource);

        $plans = SeoActionPlan::query()
            ->where('data_source_id', $dataSource->id)
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (SeoActionPlan $plan) => $this->serializePlan($plan));

        return response()->json(['data' => $plans]);
    }

    /** Run AI web-research for the property's categories + regions. */
    public function generateResearch(Request $request, DataSource $dataSource, SeoWebResearchService $research): JsonResponse
    {
        abort_unless($request->user()->hasPermission('seo.generate'), 403);
        $this->authorizeSource($request, $dataSource);

        $profile = SeoProfile::where('data_source_id', $dataSource->id)->first();

        // Seed with the property's real top opportunity keywords when available.
        $insights = $this->insights->forSource($dataSource, $request->user());
        $seeds = collect($insights['top_opportunities'] ?? [])->pluck('keyword')->filter()->all();

        $snapshot = $research->research($dataSource, $request->user(), $profile, $seeds);

        return response()->json([
            'message' => 'Web research complete.',
            'data' => $this->serializeResearch($snapshot),
        ], 201);
    }

    /** Latest stored web-research snapshot. */
    public function research(Request $request, DataSource $dataSource): JsonResponse
    {
        $this->authorizeSource($request, $dataSource);

        $snapshot = SeoResearchSnapshot::where('data_source_id', $dataSource->id)
            ->latest()
            ->first();

        return response()->json([
            'data' => $snapshot ? $this->serializeResearch($snapshot) : null,
        ]);
    }

    private function serializeResearch(SeoResearchSnapshot $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'findings' => $snapshot->findings ?? [],
            'model' => $snapshot->model,
            'provider' => $snapshot->provider,
            'created_at' => $snapshot->created_at?->toIso8601String(),
        ];
    }

    private function serializePlan(SeoActionPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'summary' => $plan->summary,
            'items' => $plan->items ?? [],
            'model' => $plan->model,
            'provider' => $plan->provider,
            'created_at' => $plan->created_at?->toIso8601String(),
        ];
    }

    private function authorizeSource(Request $request, DataSource $dataSource): void
    {
        abort_unless($dataSource->type === 'google_search_console', 404);
        abort_unless($dataSource->isAccessibleBy($request->user()), 403);
    }
}
