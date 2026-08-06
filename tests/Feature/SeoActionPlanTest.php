<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SeoActionPlan;
use App\Models\User;
use App\Services\Seo\SeoInsightAssistant;
use App\Services\Seo\SeoInsightsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Phase 3 — AI SEO action plans. The AI provider is always faked. The core
 * guarantee under test: the model is fed the deterministic findings and its
 * output is stored as a plan; it is never asked to compute the numbers itself.
 */
class SeoActionPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('security.two_factor.enabled', false);
        Config::set('seo.cache_seconds', 0);
        Config::set('ai.provider', 'openai');
        Config::set('ai.model', 'gpt-5.6-sol');
        Config::set('ai.providers.openai.api_key', 'test-key');
        Config::set('ai.providers.openai.responses_url', 'https://api.openai.com/v1/responses');
    }

    private function planResponse(): array
    {
        $plan = json_encode([
            'summary' => 'Focus on the two positions-6-20 keywords with the largest recoverable clicks.',
            'items' => [
                [
                    'title' => 'Expand the car-trading landing page',
                    'category' => 'content',
                    'priority' => 'high',
                    'rationale' => 'ghassan aboud cars sits at position 7.2 with strong impressions.',
                    'expected_impact' => 'Move 2-3 positions',
                    'references' => ['ghassan aboud cars'],
                    'requires_web_research' => false,
                    'recommendation' => "Suggested title: Ghassan Aboud Cars | UAE Vehicle Exporter\nAdd an intro paragraph mirroring search intent.",
                ],
                [
                    'title' => 'Find authoritative UAE automotive backlinks',
                    'category' => 'backlink',
                    'priority' => 'medium',
                    'rationale' => 'Needs external prospect data.',
                    'expected_impact' => 'Support domain authority',
                    'references' => ['export cars uae'],
                    'requires_web_research' => true,
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        return [
            'id' => 'resp_plan',
            'output' => [[
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => $plan, 'annotations' => []]],
            ]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 60],
        ];
    }

    private function sampleInsights(): array
    {
        return [
            'available' => true,
            'window' => ['from' => '2026-07-01', 'to' => '2026-07-28'],
            'summary' => ['clicks' => 500, 'impressions' => 20000, 'ctr' => 2.5, 'position' => 8.4],
            'profile' => ['categories' => ['automotive'], 'regions' => [['name' => 'United Arab Emirates', 'code' => 'AE']]],
            'top_opportunities' => [
                ['keyword' => 'ghassan aboud cars', 'position' => 7.2, 'impressions' => 3000, 'ctr' => 1.3, 'recoverable_clicks' => 40, 'opportunity_score' => 78],
                ['keyword' => 'export cars uae', 'position' => 12.5, 'impressions' => 1200, 'ctr' => 0.8, 'recoverable_clicks' => 20, 'opportunity_score' => 55],
            ],
            'opportunities' => ['ctr_gaps' => [], 'countries' => []],
            'trends' => ['declining' => []],
        ];
    }

    public function test_the_assistant_feeds_findings_to_the_model_and_stores_a_parsed_plan(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->planResponse())]);

        $user = $this->seoUser(['seo.view', 'seo.generate']);
        $source = $this->gscSource($user);

        $plan = app(SeoInsightAssistant::class)->generate($source, $user, $this->sampleInsights());

        $this->assertCount(2, $plan->items);
        $this->assertSame('high', $plan->items[0]['priority']);
        $this->assertStringContainsString('Suggested title', $plan->items[0]['recommendation']);
        $this->assertSame('ghassan aboud cars', $plan->items[0]['references'][0]);
        $this->assertTrue($plan->items[1]['requires_web_research']);
        $this->assertSame('openai', $plan->provider);
        $this->assertDatabaseHas('seo_action_plans', ['data_source_id' => $source->id, 'model' => 'gpt-5.6-sol']);

        // The deterministic numbers were sent to the model (not computed by it).
        Http::assertSent(fn (Request $request) => str_contains(json_encode($request->data()), 'ghassan aboud cars')
            && str_contains(json_encode($request->data()), 'opportunity_score'));
    }

    public function test_it_tolerates_a_fenced_json_response(): void
    {
        $fenced = "```json\n".json_encode([
            'summary' => 'ok',
            'items' => [[
                'title' => 'Improve title tags', 'category' => 'content', 'priority' => 'high',
                'rationale' => 'r', 'expected_impact' => 'i', 'references' => ['kw'],
            ]],
        ], JSON_THROW_ON_ERROR)."\n```";

        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'output' => [[
                'type' => 'message', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => $fenced, 'annotations' => []]],
            ]],
        ])]);

        $user = $this->seoUser(['seo.view', 'seo.generate']);
        $source = $this->gscSource($user);

        $plan = app(SeoInsightAssistant::class)->generate($source, $user, $this->sampleInsights());

        $this->assertSame('ok', $plan->summary);
        $this->assertSame('Improve title tags', $plan->items[0]['title']);
    }

    public function test_malformed_json_never_dumps_raw_json_into_the_summary(): void
    {
        // Invalid JSON: unescaped double quotes inside the summary string.
        $bad = '{ "summary": "Queries like "ghassan aboud holding" are close to the Top 5.", "items": [] }';

        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'output' => [[
                'type' => 'message', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => $bad, 'annotations' => []]],
            ]],
        ])]);

        $user = $this->seoUser(['seo.view', 'seo.generate']);
        $source = $this->gscSource($user);

        $plan = app(SeoInsightAssistant::class)->generate($source, $user, $this->sampleInsights());

        $this->assertStringNotContainsString('"items"', (string) $plan->summary);
        $this->assertStringNotContainsString('{', (string) $plan->summary);
        $this->assertStringContainsString('Queries like', (string) $plan->summary);
        $this->assertSame([], $plan->items);
    }

    public function test_it_salvages_complete_items_from_a_truncated_response(): void
    {
        // Response cut off mid-array (reasoning model ran out of output budget):
        // one complete item, then a truncated second item.
        $truncated = '{ "summary": "Focus on CTR gaps and near-top keywords.", "items": ['
            .'{"title":"Improve title tags","category":"content","priority":"high",'
            .'"rationale":"Low CTR versus expected.","expected_impact":"Lift CTR",'
            .'"references":["ghassan aboud cars"],"requires_web_research":false},'
            .'{"title":"Partial item that got cut o';

        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output' => [[
                'type' => 'message', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => $truncated, 'annotations' => []]],
            ]],
        ])]);

        $user = $this->seoUser(['seo.view', 'seo.generate']);
        $source = $this->gscSource($user);

        $plan = app(SeoInsightAssistant::class)->generate($source, $user, $this->sampleInsights());

        $this->assertCount(1, $plan->items);
        $this->assertSame('Improve title tags', $plan->items[0]['title']);
        $this->assertStringContainsString('Focus on CTR', (string) $plan->summary);
    }

    public function test_generate_endpoint_requires_the_generate_permission(): void
    {
        $user = $this->seoUser(['seo.view']); // no seo.generate
        $source = $this->gscSource($user);

        $this->actingAs($user)
            ->postJson("/api/seo/{$source->id}/action-plan")
            ->assertForbidden();
    }

    public function test_generate_endpoint_persists_a_plan(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->planResponse())]);

        $user = $this->seoUser(['seo.view', 'seo.generate']);
        $source = $this->gscSource($user);

        // Avoid live Search Console: stub the insights service.
        $mock = Mockery::mock(SeoInsightsService::class);
        $mock->shouldReceive('forSource')->once()->andReturn($this->sampleInsights());
        $this->instance(SeoInsightsService::class, $mock);

        $this->actingAs($user)
            ->postJson("/api/seo/{$source->id}/action-plan")
            ->assertCreated()
            ->assertJsonPath('data.items.0.title', 'Expand the car-trading landing page');

        $this->assertSame(1, SeoActionPlan::where('data_source_id', $source->id)->count());
    }

    /* ---- helpers ---- */

    private function gscSource(User $owner): DataSource
    {
        return DataSource::create([
            'name' => 'Aboudcar',
            'type' => 'google_search_console',
            'status' => 'connected',
            'owner_id' => $owner->id,
            'settings' => ['site_url' => 'https://gaholding.com'],
        ]);
    }

    /** @param array<int, string> $permissions */
    private function seoUser(array $permissions): User
    {
        $role = Role::create(['name' => 'seo_'.uniqid(), 'label' => 'SEO']);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'group' => 'SEO']);
            $role->permissions()->attach($permission->id);
        }

        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
