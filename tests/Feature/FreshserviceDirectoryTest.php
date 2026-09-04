<?php

namespace Tests\Feature;

use App\Models\DataSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Freshservice agent and group directory cache (`freshservice:refresh-directory`).
 *
 * The command runs unattended at 01:00 daily against a third-party API, so the
 * behaviour worth pinning is how it treats a source that misbehaves: a refusal
 * must be reported and must not leave a half-written or emptied cache, and the
 * page walk must stay bounded however much the vendor returns.
 */
class FreshserviceDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://company.freshservice.com';

    /* ------------------------------------------------------------------
     | Source selection
     |------------------------------------------------------------------ */

    public function test_it_succeeds_and_says_so_when_no_source_is_configured(): void
    {
        Http::fake();

        $this->artisan('freshservice:refresh-directory')
            ->expectsOutputToContain('No Freshservice sources found.')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_it_ignores_a_source_that_is_not_connected(): void
    {
        $this->source(status: 'disconnected');
        Http::fake();

        $this->artisan('freshservice:refresh-directory')
            ->expectsOutputToContain('No Freshservice sources found.')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_the_source_id_option_limits_the_refresh_to_one_source(): void
    {
        $first = $this->source(name: 'Freshservice A');
        $this->source(name: 'Freshservice B');
        $this->fakeDirectory(agents: [['id' => 11, 'name' => 'Aisha Khan']]);

        $this->artisan('freshservice:refresh-directory', ['--source-id' => $first->id])
            ->assertExitCode(0);

        $this->assertSame(
            [$first->id],
            DB::table('freshservice_directory_cache')->distinct()->pluck('data_source_id')->all()
        );
    }

    /* ------------------------------------------------------------------
     | Caching
     |------------------------------------------------------------------ */

    public function test_it_caches_agents_and_groups(): void
    {
        $source = $this->source();
        $this->fakeDirectory(
            agents: [
                ['id' => 11, 'first_name' => 'Aisha', 'last_name' => 'Khan'],
                ['id' => 12, 'first_name' => 'Tom', 'last_name' => 'Reed'],
            ],
            groups: [['id' => 21, 'name' => 'Service Desk']],
        );

        $this->artisan('freshservice:refresh-directory')
            ->expectsOutputToContain('Cached 2 agents')
            ->expectsOutputToContain('Cached 1 groups')
            ->assertExitCode(0);

        $this->assertDatabaseHas('freshservice_directory_cache', [
            'data_source_id' => $source->id,
            'entity_type' => 'agent',
            'entity_id' => 11,
            'name' => 'Aisha Khan',
        ]);
        $this->assertDatabaseHas('freshservice_directory_cache', [
            'data_source_id' => $source->id,
            'entity_type' => 'group',
            'entity_id' => 21,
            'name' => 'Service Desk',
        ]);
    }

    public function test_a_second_run_updates_rather_than_duplicates(): void
    {
        $source = $this->source();

        $name = 'Aisha Khan';
        Http::fake(function (Request $request) use (&$name) {
            return str_contains($request->url(), '/agents')
                ? Http::response(['agents' => [['id' => 11, 'name' => $name]]])
                : Http::response(['groups' => []]);
        });

        $this->artisan('freshservice:refresh-directory')->assertExitCode(0);

        $name = 'Aisha Khan-Reed';
        $this->artisan('freshservice:refresh-directory')->assertExitCode(0);

        $this->assertDatabaseCount('freshservice_directory_cache', 1);
        $this->assertDatabaseHas('freshservice_directory_cache', [
            'data_source_id' => $source->id,
            'entity_id' => 11,
            'name' => 'Aisha Khan-Reed',
        ]);
    }

    public function test_it_skips_records_with_no_usable_identity(): void
    {
        $this->source();
        $this->fakeDirectory(agents: [
            ['id' => 11, 'name' => 'Aisha Khan'],
            ['first_name' => 'No', 'last_name' => 'Identifier'],
            ['id' => 13, 'name' => ''],
        ]);

        $this->artisan('freshservice:refresh-directory')->assertExitCode(0);

        $this->assertDatabaseCount('freshservice_directory_cache', 1);
        $this->assertDatabaseHas('freshservice_directory_cache', ['entity_id' => 11]);
    }

    public function test_it_walks_pages_until_a_short_page_ends_the_run(): void
    {
        $this->source();
        $page1 = $this->agents(1, 100);
        $page2 = $this->agents(101, 5);

        Http::fake(function (Request $request) use ($page1, $page2) {
            if (str_contains($request->url(), '/agents')) {
                return Http::response([
                    'agents' => (int) ($request->data()['page'] ?? 1) === 1 ? $page1 : $page2,
                ]);
            }

            return Http::response(['groups' => []]);
        });

        $this->artisan('freshservice:refresh-directory')
            ->expectsOutputToContain('Cached 105 agents')
            ->assertExitCode(0);

        $this->assertDatabaseCount('freshservice_directory_cache', 105);
    }

    public function test_the_page_walk_stays_bounded_when_every_page_is_full(): void
    {
        // A vendor returning a full page forever must not spin the command.
        Config::set('integrations.freshservice.max_directory_pages', 3);
        $this->source();

        $requests = 0;
        Http::fake(function (Request $request) use (&$requests) {
            if (str_contains($request->url(), '/agents')) {
                $requests++;

                return Http::response(['agents' => $this->agents(($requests - 1) * 100 + 1, 100)]);
            }

            return Http::response(['groups' => []]);
        });

        $this->artisan('freshservice:refresh-directory')->assertExitCode(0);

        $this->assertSame(3, $requests);
        $this->assertDatabaseCount('freshservice_directory_cache', 300);
    }

    /* ------------------------------------------------------------------
     | Failure handling
     |------------------------------------------------------------------ */

    public function test_rejected_credentials_fail_the_run_without_emptying_the_cache(): void
    {
        // The cache is what the dashboard reads. A refused refresh must leave
        // yesterday's directory in place rather than blanking it.
        $this->source();
        $refused = false;
        Http::fake(function (Request $request) use (&$refused) {
            if ($refused) {
                return Http::response(['message' => 'nope'], 401);
            }

            return str_contains($request->url(), '/agents')
                ? Http::response(['agents' => [['id' => 11, 'name' => 'Aisha Khan']]])
                : Http::response(['groups' => []]);
        });

        $this->artisan('freshservice:refresh-directory')->assertExitCode(0);

        $refused = true;

        $this->artisan('freshservice:refresh-directory')
            ->expectsOutputToContain('Invalid Freshservice credentials')
            ->assertExitCode(1);

        $this->assertDatabaseCount('freshservice_directory_cache', 1);
        $this->assertDatabaseHas('freshservice_directory_cache', ['name' => 'Aisha Khan']);
    }

    public function test_rate_limiting_is_reported_as_such(): void
    {
        $this->source();
        Http::fake(fn () => Http::response([], 429));

        $this->artisan('freshservice:refresh-directory')
            ->expectsOutputToContain('Rate limited by Freshservice')
            ->assertExitCode(1);
    }

    public function test_an_unexpected_payload_shape_fails_the_run(): void
    {
        $this->source();
        Http::fake(fn () => Http::response(['agents' => 'not-a-list']));

        $this->artisan('freshservice:refresh-directory')
            ->expectsOutputToContain('Unexpected response format from Freshservice')
            ->assertExitCode(1);

        $this->assertDatabaseCount('freshservice_directory_cache', 0);
    }

    public function test_one_failing_source_does_not_stop_the_others(): void
    {
        $good = $this->source(name: 'Good', baseUrl: 'https://good.freshservice.com');
        $this->source(name: 'Bad', baseUrl: 'https://bad.freshservice.com');

        Http::fake([
            'good.freshservice.com/api/v2/agents*' => Http::response(['agents' => [['id' => 11, 'name' => 'Aisha Khan']]]),
            'good.freshservice.com/api/v2/groups*' => Http::response(['groups' => []]),
            'bad.freshservice.com/*' => Http::response([], 500),
        ]);

        $this->artisan('freshservice:refresh-directory')
            ->expectsOutputToContain('Refreshed: 1')
            ->assertExitCode(1);

        $this->assertDatabaseCount('freshservice_directory_cache', 1);
        $this->assertDatabaseHas('freshservice_directory_cache', [
            'data_source_id' => $good->id,
            'entity_id' => 11,
        ]);
    }

    public function test_a_source_url_the_guard_refuses_is_not_called(): void
    {
        $this->source(baseUrl: 'http://company.freshservice.com');
        Http::fake();

        $this->artisan('freshservice:refresh-directory')->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertDatabaseCount('freshservice_directory_cache', 0);
    }

    /* ------------------------------------------------------------------
     | Helpers
     |------------------------------------------------------------------ */

    private function source(
        string $name = 'Freshservice ITSM',
        string $status = 'connected',
        string $baseUrl = self::BASE,
    ): DataSource {
        $source = DataSource::create([
            'name' => $name,
            'type' => 'freshservice',
            'base_url' => $baseUrl,
            'status' => $status,
            'settings' => [],
        ]);

        $source->apiConfiguration()->create([
            'auth_type' => 'basic',
            'encrypted_credentials' => ['username' => 'fake-api-key', 'password' => 'X'],
            'timeout_seconds' => 30,
            'retry_count' => 0,
        ]);

        return $source;
    }

    /**
     * @param  list<array<string, mixed>>  $agents
     * @param  list<array<string, mixed>>  $groups
     */
    private function fakeDirectory(array $agents = [], array $groups = []): void
    {
        Http::fake(function (Request $request) use ($agents, $groups) {
            return str_contains($request->url(), '/agents')
                ? Http::response(['agents' => $agents])
                : Http::response(['groups' => $groups]);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function agents(int $from, int $count): array
    {
        return collect(range($from, $from + $count - 1))
            ->map(fn (int $id) => ['id' => $id, 'name' => "Agent {$id}"])
            ->all();
    }
}
