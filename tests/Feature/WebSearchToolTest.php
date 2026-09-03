<?php

namespace Tests\Feature;

use App\Models\AiToolDefinition;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Ai\ToolRegistry;
use App\Services\Ai\Tools\WebSearchTool;
use App\Services\Integrations\WebSearchConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Cover for the chat-only, admin-configurable web_search tool (ADR-002).
 *
 * The provider is always faked — automated tests never make live outbound
 * calls. These assert the guard rails hold: allow-listed host only, permission
 * required, results returned as cited untrusted text, and the registry only
 * builds the tool once an administrator has configured a provider.
 */
class WebSearchToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('security.two_factor.enabled', false);
    }

    /** Provider settings as the registry would assemble them from a tool row. */
    private function providerConfig(array $overrides = []): array
    {
        return [
            'endpoint' => 'https://search.example.com/v1/search',
            'allowed_hosts' => ['search.example.com'],
            'auth_scheme' => 'bearer',
            'key_header' => 'X-API-Key',
            'api_key' => 'test-key',
            'max_results' => 5,
            'timeout_seconds' => 15,
            'retry_attempts' => 1,
            'response_limit_bytes' => 1_000_000,
            ...$overrides,
        ];
    }

    private function tool(array $config): WebSearchTool
    {
        return new WebSearchTool(app(WebSearchConnector::class), $config);
    }

    public function test_it_returns_cited_results_for_an_authorized_user(): void
    {
        Http::fake([
            'search.example.com/*' => Http::response([
                'results' => [
                    ['title' => 'Result One', 'url' => 'https://a.example/one', 'snippet' => 'First.'],
                    ['title' => 'Result Two', 'url' => 'https://b.example/two', 'snippet' => 'Second.'],
                ],
            ]),
        ]);

        $result = $this->tool($this->providerConfig())
            ->execute($this->searchUser(), ['query' => 'latest news', 'limit' => 5]);

        $this->assertCount(2, $result->data['results']);
        $this->assertSame('https://a.example/one', $result->citations[0]['url']);
        $this->assertSame('web_search', $result->citations[0]['source_type']);
        // Distinct source_id per result so the assistant does not collapse them.
        $this->assertNotSame($result->citations[0]['source_id'], $result->citations[1]['source_id']);
        $this->assertSame('search.example.com', $result->summary['provider_host']);
    }

    public function test_it_sends_the_api_key_and_query_to_the_configured_endpoint(): void
    {
        Http::fake(['search.example.com/*' => Http::response(['results' => []])]);

        $this->tool($this->providerConfig())
            ->execute($this->searchUser(), ['query' => 'gdp figures', 'limit' => 3]);

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://search.example.com/v1/search')
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request['q'] === 'gdp figures');
    }

    public function test_a_user_without_permission_is_refused(): void
    {
        Http::fake(['search.example.com/*' => Http::response(['results' => []])]);

        $this->expectException(RuntimeException::class);

        $this->tool($this->providerConfig())
            ->execute($this->userWithoutSearch(), ['query' => 'anything', 'limit' => 3]);
    }

    public function test_an_endpoint_off_the_allow_list_is_refused(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        $this->expectException(RuntimeException::class);

        // Host not present in allowed_hosts.
        $this->tool($this->providerConfig(['endpoint' => 'https://not-allowed.example.com/v1/search']))
            ->execute($this->searchUser(), ['query' => 'anything', 'limit' => 3]);
    }

    /* ==============================================================
     * Registry integration: the admin configuration drives it
     * ============================================================== */

    public function test_the_registry_builds_web_search_once_a_provider_is_configured(): void
    {
        AiToolDefinition::query()->delete();
        $this->configuredToolRow();

        $registry = app(ToolRegistry::class);

        $this->assertContains('web_search', $registry->names());
        $this->assertTrue($registry->has('web_search'));
    }

    public function test_the_registry_skips_an_enabled_web_search_tool_with_no_provider(): void
    {
        AiToolDefinition::query()->delete();

        // Enabled but no endpoint / hosts / key — must not be offered.
        AiToolDefinition::create([
            'name' => 'web_search',
            'label' => 'Global web search',
            'description' => 'Search the public web for general knowledge facts not in our data sources.',
            'handler' => 'web_search',
            'source_types' => [],
            'is_enabled' => true,
            'sort_order' => 90,
            'options' => ['endpoint' => null, 'allowed_hosts' => []],
            'secret_options' => null,
        ]);

        $this->assertNotContains('web_search', app(ToolRegistry::class)->names());
    }

    public function test_a_configured_web_search_tool_is_reported_as_standalone(): void
    {
        AiToolDefinition::query()->delete();
        $this->configuredToolRow();

        $standalone = app(ToolRegistry::class)->standaloneTools();

        $this->assertCount(1, $standalone);
        $this->assertSame('web_search', $standalone[0]->name());
    }

    public function test_the_encrypted_api_key_round_trips(): void
    {
        $tool = $this->configuredToolRow();

        $this->assertSame('secret-key', $tool->fresh()->secret_options['api_key']);
        // The raw column must not hold the plaintext key.
        $raw = DB::table('ai_tools')->where('id', $tool->id)->value('secret_options');
        $this->assertStringNotContainsString('secret-key', (string) $raw);
    }

    private function configuredToolRow(): AiToolDefinition
    {
        // The migration seeds a disabled web_search row; replace it so the
        // unique name constraint does not clash.
        AiToolDefinition::where('name', 'web_search')->delete();

        return AiToolDefinition::create([
            'name' => 'web_search',
            'label' => 'Global web search',
            'description' => 'Search the public web for current general knowledge facts not held in our data sources.',
            'handler' => 'web_search',
            'source_types' => [],
            'is_enabled' => true,
            'sort_order' => 90,
            'options' => [
                'endpoint' => 'https://search.example.com/v1/search',
                'allowed_hosts' => ['search.example.com'],
                'auth_scheme' => 'bearer',
                'max_results' => 5,
                'timeout_seconds' => 15,
                'cache_seconds' => 0,
            ],
            'secret_options' => ['api_key' => 'secret-key'],
        ]);
    }

    private function searchUser(): User
    {
        // `firstOrCreate`, not `create`: migration 2026_07_31_000600 already
        // seeds `ai.web_search`, so creating it again violates the unique index.
        $permission = Permission::firstOrCreate(
            ['name' => WebSearchTool::PERMISSION],
            ['label' => 'Use web search', 'group' => 'AI'],
        );
        $role = Role::create(['name' => 'analyst', 'label' => 'Analyst']);
        $role->permissions()->attach($permission->id);

        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function userWithoutSearch(): User
    {
        Role::firstOrCreate(['name' => 'viewer'], ['label' => 'Viewer']);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('name', 'viewer')->firstOrFail());

        return $user;
    }
}
