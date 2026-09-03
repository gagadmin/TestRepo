<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SeoProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 1 SEO insights: deterministic Search Console analysis + profiles.
 * Google is always faked — no live calls.
 */
class SeoInsightsTest extends TestCase
{
    use RefreshDatabase;

    private string $credentialPath;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('security.two_factor.enabled', false);
        Config::set('seo.cache_seconds', 0);

        // A real (throwaway) service-account key so the GSC OAuth JWT can sign,
        // mirroring GoogleSearchConsoleTest.
        $key = @openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false || ! openssl_pkey_export($key, $privateKey)) {
            $this->markTestSkipped('OpenSSL is unavailable to generate a test key.');
        }

        $this->credentialPath = tempnam(sys_get_temp_dir(), 'seo-gsc-');
        file_put_contents($this->credentialPath, json_encode([
            'type' => 'service_account',
            'client_email' => 'seo-test@example.test',
            'private_key' => $privateKey,
        ], JSON_THROW_ON_ERROR));

        Config::set('services.search_console.site_url', 'https://gaholding.com');
        Config::set('services.search_console.credentials', $this->credentialPath);
        Config::set('services.search_console.timeout_seconds', 5);
    }

    protected function tearDown(): void
    {
        if (isset($this->credentialPath) && is_file($this->credentialPath)) {
            unlink($this->credentialPath);
        }

        parent::tearDown();
    }

    public function test_it_ranks_positions_6_20_opportunities_and_flags_brand_terms(): void
    {
        $this->fakeSearchConsole();
        $user = $this->seoUser();
        $source = $this->gscSource($user);
        SeoProfile::create([
            'data_source_id' => $source->id,
            'brand_terms' => ['ghassan aboud'],
            'regions' => [['name' => 'United Arab Emirates', 'code' => 'AE']],
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/seo/{$source->id}")
            ->assertOk()
            ->json('data');

        $this->assertTrue($response['available']);

        $positions = collect($response['opportunities']['positions_6_20']);
        // Only the 6–20 keywords above the impression threshold appear.
        $this->assertEqualsCanonicalizing(
            ['ghassan aboud cars', 'export cars uae'],
            $positions->pluck('keyword')->all(),
        );
        // The branded keyword is flagged.
        $this->assertTrue($positions->firstWhere('keyword', 'ghassan aboud cars')['is_brand']);
        // A position-3 keyword is not an opportunity.
        $this->assertNotContains('aboud cars', $positions->pluck('keyword'));
    }

    public function test_it_reports_unavailable_when_search_console_fails(): void
    {
        Config::set('services.search_console.credentials', '');
        $user = $this->seoUser();
        $source = $this->gscSource($user);

        $data = $this->actingAs($user)
            ->getJson("/api/seo/{$source->id}")
            ->assertOk()
            ->json('data');

        $this->assertFalse($data['available']);
        $this->assertSame('unavailable', $data['sections']['positions_6_20']);
    }

    public function test_saving_a_profile_persists_categories_and_regions(): void
    {
        $user = $this->seoUser();
        $source = $this->gscSource($user);

        $this->actingAs($user)
            ->putJson("/api/seo/{$source->id}/profile", [
                'categories' => ['automotive', 'spare parts', 'export cars'],
                'regions' => [['name' => 'United Arab Emirates', 'code' => 'AE']],
                'brand_terms' => ['ghassan aboud'],
            ])
            ->assertOk();

        $this->assertDatabaseHas('seo_profiles', ['data_source_id' => $source->id]);
        $profile = SeoProfile::where('data_source_id', $source->id)->first();
        $this->assertEqualsCanonicalizing(['automotive', 'spare parts', 'export cars'], $profile->categories);
        $this->assertSame('AE', $profile->regions[0]['code']);
    }

    public function test_saving_a_profile_busts_the_insights_cache(): void
    {
        // Caching on: the stale-profile bug only appears when results are cached.
        Config::set('seo.cache_seconds', 900);
        $this->fakeSearchConsole();
        $user = $this->seoUser();
        $source = $this->gscSource($user);

        // Warm the cache (no profile yet).
        $before = $this->actingAs($user)->getJson("/api/seo/{$source->id}")->json('data');
        $this->assertNull($before['profile']);

        $this->actingAs($user)
            ->putJson("/api/seo/{$source->id}/profile", ['categories' => ['automotive']])
            ->assertOk();

        // The reload must reflect the new profile, not the cached one.
        $after = $this->actingAs($user)->getJson("/api/seo/{$source->id}")->json('data');
        $this->assertSame(['automotive'], $after['profile']['categories']);
    }

    public function test_a_user_without_seo_view_is_forbidden(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::create(['name' => 'plain', 'label' => 'Plain']));
        $source = $this->gscSource($user);

        $this->actingAs($user)->getJson("/api/seo/{$source->id}")->assertForbidden();
    }

    /* ---- helpers ---- */

    private function fakeSearchConsole(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => function (Request $request) {
                // Totals request has rowLimit 1 and no dimensions.
                if (($request['rowLimit'] ?? null) === 1) {
                    return Http::response(['rows' => [[
                        'clicks' => 500, 'impressions' => 20000, 'ctr' => 0.025, 'position' => 8.4,
                    ]]]);
                }

                $dimension = $request['dimensions'][0] ?? 'query';

                return Http::response(['rows' => $this->rowsFor($dimension)]);
            },
        ]);
    }

    private function rowsFor(string $dimension): array
    {
        if ($dimension === 'query') {
            return [
                ['keys' => ['ghassan aboud cars'], 'clicks' => 40, 'impressions' => 3000, 'ctr' => 0.013, 'position' => 7.2],
                ['keys' => ['export cars uae'], 'clicks' => 10, 'impressions' => 1200, 'ctr' => 0.008, 'position' => 12.5],
                ['keys' => ['aboud cars'], 'clicks' => 300, 'impressions' => 5000, 'ctr' => 0.06, 'position' => 3.1],
                ['keys' => ['tiny term'], 'clicks' => 0, 'impressions' => 10, 'ctr' => 0.0, 'position' => 9.0],
            ];
        }

        if ($dimension === 'page') {
            return [
                ['keys' => ['https://gaholding.com/cars'], 'clicks' => 20, 'impressions' => 4000, 'ctr' => 0.005, 'position' => 9.0],
            ];
        }

        // country
        return [
            ['keys' => ['are'], 'clicks' => 200, 'impressions' => 12000, 'ctr' => 0.017, 'position' => 9.5],
            ['keys' => ['sau'], 'clicks' => 5, 'impressions' => 800, 'ctr' => 0.006, 'position' => 15.0],
        ];
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

    private function seoUser(): User
    {
        // `firstOrCreate`, not `create`: migration 2026_07_31_000800 already
        // seeds `seo.view`, so creating it again violates the unique index.
        $permission = Permission::firstOrCreate(
            ['name' => 'seo.view'],
            ['label' => 'View SEO insights', 'group' => 'SEO'],
        );
        $role = Role::create(['name' => 'seo_analyst', 'label' => 'SEO Analyst']);
        $role->permissions()->attach($permission->id);

        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
