<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\SeoProfile;
use App\Models\SeoResearchSnapshot;
use App\Models\User;
use App\Services\Seo\SeoWebResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 4 — AI web research via the OpenAI web search tool. OpenAI is always
 * faked. The findings are cited, qualitative, and stored separately from GSC.
 */
class SeoWebResearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('ai.providers.openai.api_key', 'test-key');
        Config::set('ai.providers.openai.responses_url', 'https://api.openai.com/v1/responses');
        Config::set('web_search.openai_model', 'gpt-4o');
    }

    private function fakeResearch(): void
    {
        $findings = json_encode([
            'competitors' => [
                ['name' => 'Al Futtaim', 'domain' => 'alfuttaim.com', 'note' => 'Large UAE auto group', 'url' => 'https://ex.com/a'],
            ],
            'backlink_targets' => [
                ['name' => 'Dubai Chamber directory', 'type' => 'directory', 'why' => 'Authoritative UAE listing', 'url' => 'https://ex.com/b'],
            ],
            'technical_signals' => [
                ['observation' => 'Slow product pages', 'recommendation' => 'Optimize images', 'url' => 'https://ex.com/c'],
            ],
            'content_ideas' => [
                ['idea' => 'Export cars guide', 'target_keyword' => 'export cars uae', 'url' => 'https://ex.com/d'],
            ],
        ], JSON_THROW_ON_ERROR);

        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'output' => [[
                'type' => 'message',
                'role' => 'assistant',
                'content' => [[
                    'type' => 'output_text',
                    'text' => $findings,
                    'annotations' => [
                        ['type' => 'url_citation', 'url' => 'https://ex.com/a', 'title' => 'A'],
                        ['type' => 'url_citation', 'url' => 'https://ex.com/b', 'title' => 'B'],
                    ],
                ]],
            ]],
        ])]);
    }

    public function test_it_researches_and_stores_cited_findings(): void
    {
        $this->fakeResearch();
        $user = User::factory()->create(['is_active' => true]);
        $source = $this->gscSource($user);
        $profile = SeoProfile::create([
            'data_source_id' => $source->id,
            'categories' => ['automotive', 'export cars'],
            'regions' => [['name' => 'United Arab Emirates', 'code' => 'AE']],
        ]);

        $snapshot = app(SeoWebResearchService::class)
            ->research($source, $user, $profile, ['ghassan aboud cars']);

        $this->assertSame('Al Futtaim', $snapshot->findings['competitors'][0]['name']);
        $this->assertSame('directory', $snapshot->findings['backlink_targets'][0]['type']);
        $this->assertContains('https://ex.com/a', $snapshot->findings['sources']);
        $this->assertSame('openai', $snapshot->provider);
        $this->assertDatabaseHas('seo_research_snapshots', ['data_source_id' => $source->id]);

        // The categories and region were sent to the model.
        Http::assertSent(fn (Request $r) => str_contains(json_encode($r->data()), 'automotive')
            && str_contains(json_encode($r->data()), 'United Arab Emirates')
            && collect($r['tools'] ?? [])->contains(fn ($t) => ($t['type'] ?? null) === 'web_search'));
    }

    public function test_it_requires_categories(): void
    {
        $this->fakeResearch();
        $user = User::factory()->create(['is_active' => true]);
        $source = $this->gscSource($user);
        $profile = SeoProfile::create(['data_source_id' => $source->id, 'categories' => []]);

        $this->expectException(RuntimeException::class);
        app(SeoWebResearchService::class)->research($source, $user, $profile, []);
    }

    public function test_it_requires_the_openai_provider_configured(): void
    {
        Config::set('ai.providers.openai.api_key', null);
        $user = User::factory()->create(['is_active' => true]);
        $source = $this->gscSource($user);
        $profile = SeoProfile::create(['data_source_id' => $source->id, 'categories' => ['automotive']]);

        $this->expectException(RuntimeException::class);
        app(SeoWebResearchService::class)->research($source, $user, $profile, []);
    }

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
}
