<?php

namespace Tests\Feature;

use App\Models\AiToolDefinition;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Ai\Providers\OpenAiResponsesProvider;
use App\Services\Ai\ToolRegistry;
use App\Services\Ai\Tools\OpenAiWebSearchTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Cover for the OpenAI-backed web search tool (ADR-002).
 *
 * The OpenAI Responses API is always faked. These assert the tool reuses the
 * configured OpenAI key, parses url_citation annotations into cited results,
 * enforces the permission, and is only offered once the OpenAI provider is
 * configured.
 */
class OpenAiWebSearchToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('security.two_factor.enabled', false);
        Config::set('ai.providers.openai.api_key', 'test-key');
        Config::set('ai.providers.openai.responses_url', 'https://api.openai.com/v1/responses');
    }

    private function tool(array $config = ['model' => 'gpt-4o']): OpenAiWebSearchTool
    {
        return new OpenAiWebSearchTool(app(OpenAiResponsesProvider::class), $config);
    }

    private function fakeResponse(): array
    {
        return [
            'id' => 'resp_1',
            'output' => [
                ['type' => 'web_search_call', 'id' => 'ws_1', 'action' => ['type' => 'search']],
                [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Inflation eased to 3% last month.',
                        'annotations' => [
                            ['type' => 'url_citation', 'url' => 'https://news.example/a', 'title' => 'Report A'],
                            ['type' => 'url_citation', 'url' => 'https://news.example/b', 'title' => 'Report B'],
                        ],
                    ]],
                ],
            ],
            'usage' => ['input_tokens' => 50, 'output_tokens' => 30],
        ];
    }

    public function test_it_returns_the_answer_and_cited_sources(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->fakeResponse())]);

        $result = $this->tool()->execute($this->searchUser(), ['query' => 'inflation last month']);

        $this->assertSame('Inflation eased to 3% last month.', $result->data['answer']);
        $this->assertCount(2, $result->citations);
        $this->assertSame('https://news.example/a', $result->citations[0]['url']);
        $this->assertNotSame($result->citations[0]['source_id'], $result->citations[1]['source_id']);
        $this->assertSame('gpt-4o', $result->summary['model']);
    }

    public function test_it_enables_the_web_search_tool_on_the_request(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->fakeResponse())]);

        $this->tool()->execute($this->searchUser(), ['query' => 'anything']);

        Http::assertSent(function (Request $request) {
            $tools = $request['tools'] ?? [];

            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request['model'] === 'gpt-4o'
                && collect($tools)->contains(fn ($tool) => ($tool['type'] ?? null) === 'web_search');
        });
    }

    public function test_a_user_without_permission_is_refused(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->fakeResponse())]);

        $this->expectException(RuntimeException::class);

        $this->tool()->execute($this->userWithoutSearch(), ['query' => 'anything']);
    }

    public function test_the_registry_builds_it_when_the_openai_key_is_configured(): void
    {
        AiToolDefinition::query()->where('name', 'web_search_openai')->delete();
        $this->toolRow();

        $this->assertContains('web_search_openai', app(ToolRegistry::class)->names());
    }

    public function test_the_registry_skips_it_when_the_openai_key_is_absent(): void
    {
        Config::set('ai.providers.openai.api_key', null);
        AiToolDefinition::query()->where('name', 'web_search_openai')->delete();
        $this->toolRow();

        $this->assertNotContains('web_search_openai', app(ToolRegistry::class)->names());
    }

    private function toolRow(): AiToolDefinition
    {
        return AiToolDefinition::create([
            'name' => 'web_search_openai',
            'label' => 'Global web search (OpenAI)',
            'description' => 'Search the public web for current general knowledge facts not held in our data sources.',
            'handler' => 'openai_web_search',
            'source_types' => [],
            'is_enabled' => true,
            'sort_order' => 91,
            'options' => ['model' => 'gpt-4o', 'max_output_tokens' => 1500, 'tool_type' => 'web_search'],
        ]);
    }

    private function searchUser(): User
    {
        // `firstOrCreate`, not `create`: migration 2026_07_31_000600 already
        // seeds `ai.web_search`, so creating it again violates the unique index.
        $permission = Permission::firstOrCreate(
            ['name' => OpenAiWebSearchTool::PERMISSION],
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
