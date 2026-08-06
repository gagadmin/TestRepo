<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\DataSource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Ai\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_chat_executes_only_an_approved_tool_and_persists_citations(): void
    {
        config([
            'ai.provider' => 'openai',
            'ai.model' => 'gpt-5.6-sol',
            'ai.providers.openai.api_key' => 'test-key',
            'integrations.require_https' => true,
        ]);

        $user = $this->reportingUser();
        $source = DataSource::create([
            'name' => 'Sales CRM',
            'type' => 'crm',
            'base_url' => 'https://crm.example.com',
            'status' => 'connected',
            'owner_id' => $user->id,
            'settings' => ['data_path' => '/pipeline'],
        ]);
        $source->apiConfiguration()->create([
            'auth_type' => 'none',
            'timeout_seconds' => 10,
            'retry_count' => 0,
        ]);

        $aiRequestCount = 0;
        Http::fake(function (Request $request) use (&$aiRequestCount, $source) {
            if ($request->url() === 'https://api.openai.com/v1/responses') {
                $aiRequestCount++;

                if ($aiRequestCount === 1) {
                    return Http::response([
                        'id' => 'resp_tool_call',
                        'output' => [[
                            'type' => 'function_call',
                            'call_id' => 'call_pipeline',
                            'name' => 'get_crm_pipeline',
                            'arguments' => json_encode([
                                'data_source_id' => $source->id,
                                'date_from' => '2026-07-01',
                                'date_to' => '2026-07-27',
                                'limit' => 50,
                            ]),
                        ]],
                        'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
                    ]);
                }

                $this->assertTrue(collect($request->data()['input'])->contains(
                    fn ($item) => ($item['type'] ?? null) === 'function_call_output'
                        && ($item['call_id'] ?? null) === 'call_pipeline'
                ));

                return Http::response([
                    'id' => 'resp_final',
                    'output' => [[
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => [[
                            'type' => 'output_text',
                            'text' => 'The qualified CRM pipeline is AED 4.2 million.',
                            'annotations' => [],
                        ]],
                    ]],
                    'usage' => ['input_tokens' => 160, 'output_tokens' => 35],
                ]);
            }

            if (str_starts_with($request->url(), 'https://crm.example.com/pipeline')) {
                return Http::response([
                    'currency' => 'AED',
                    'qualified_pipeline' => 4200000,
                    'opportunities' => 18,
                ]);
            }

            return Http::response([], 404);
        });

        $response = $this->actingAs($user)->postJson('/api/ai/chat', [
            'content' => 'What is our qualified CRM pipeline this month?',
        ])->assertOk()
            ->assertJsonPath('message.model', 'gpt-5.6-sol')
            ->assertJsonPath('message.citations.0.source_name', 'Sales CRM');

        $conversationId = $response->json('conversation.id');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationId,
            'role' => 'assistant',
            'response_id' => 'resp_final',
        ]);
        $this->assertDatabaseHas('ai_tool_executions', [
            'conversation_id' => $conversationId,
            'tool_name' => 'get_crm_pipeline',
            'status' => 'succeeded',
        ]);

        $rawConversation = DB::table('conversations')->where('id', $conversationId)->value('title');
        $rawUserMessage = DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->where('role', 'user')
            ->value('content');
        $rawArguments = DB::table('ai_tool_executions')
            ->where('conversation_id', $conversationId)
            ->value('arguments');
        $this->assertStringNotContainsString('qualified CRM pipeline', $rawConversation);
        $this->assertStringNotContainsString('qualified CRM pipeline', $rawUserMessage);
        $this->assertStringNotContainsString('date_from', $rawArguments);

        Http::assertSent(function (Request $request) {
            if ($request->url() !== 'https://api.openai.com/v1/responses') {
                return false;
            }

            return $request->data()['store'] === false
                && $request->data()['parallel_tool_calls'] === false
                && collect($request->data()['tools'])->every(
                    fn ($tool) => $tool['strict'] === true
                        && $tool['parameters']['additionalProperties'] === false
                );
        });
    }

    public function test_google_ai_studio_executes_an_approved_tool_and_returns_a_grounded_answer(): void
    {
        config([
            'ai.provider' => 'google',
            'ai.model' => 'gemini-3.5-flash',
            'ai.providers.google.api_key' => 'test-gemini-key',
            'ai.providers.google.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'integrations.require_https' => true,
        ]);

        $user = $this->reportingUser();
        $source = DataSource::create([
            'name' => 'Sales CRM',
            'type' => 'crm',
            'base_url' => 'https://crm.example.com',
            'status' => 'connected',
            'owner_id' => $user->id,
            'settings' => ['data_path' => '/pipeline'],
        ]);
        $source->apiConfiguration()->create([
            'auth_type' => 'none',
            'timeout_seconds' => 10,
            'retry_count' => 0,
        ]);

        $geminiRequestCount = 0;
        Http::fake(function (Request $request) use (&$geminiRequestCount, $source) {
            if ($request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent') {
                $geminiRequestCount++;
                $this->assertTrue($request->hasHeader('x-goog-api-key', 'test-gemini-key'));
                $this->assertSame(
                    'get_crm_pipeline',
                    data_get($request->data(), 'tools.0.functionDeclarations.4.name')
                );
                $this->assertArrayNotHasKey(
                    'additionalProperties',
                    data_get($request->data(), 'tools.0.functionDeclarations.0.parameters')
                );

                if ($geminiRequestCount === 1) {
                    return Http::response([
                        'responseId' => 'gemini_tool_response',
                        'candidates' => [[
                            'content' => [
                                'role' => 'model',
                                'parts' => [[
                                    'functionCall' => [
                                        'id' => 'google_pipeline_call',
                                        'name' => 'get_crm_pipeline',
                                        'args' => [
                                            'data_source_id' => $source->id,
                                            'date_from' => '2026-07-01',
                                            'date_to' => '2026-07-27',
                                            'limit' => 50,
                                        ],
                                    ],
                                    'thoughtSignature' => 'test-thought-signature',
                                ]],
                            ],
                        ]],
                        'usageMetadata' => [
                            'promptTokenCount' => 80,
                            'candidatesTokenCount' => 12,
                        ],
                    ]);
                }

                $functionResponse = collect($request->data()['contents'])
                    ->flatMap(fn (array $content) => $content['parts'])
                    ->first(fn (array $part) => isset($part['functionResponse']));
                $this->assertSame(
                    'get_crm_pipeline',
                    data_get($functionResponse, 'functionResponse.name')
                );
                $this->assertSame(
                    'test-thought-signature',
                    collect($request->data()['contents'])
                        ->flatMap(fn (array $content) => $content['parts'])
                        ->first(fn (array $part) => isset($part['functionCall']))['thoughtSignature'] ?? null
                );
                $this->assertSame(
                    4200000,
                    data_get($functionResponse, 'functionResponse.response.data.qualified_pipeline')
                );

                return Http::response([
                    'responseId' => 'gemini_final_response',
                    'candidates' => [[
                        'content' => [
                            'role' => 'model',
                            'parts' => [[
                                'text' => 'The qualified CRM pipeline is AED 4.2 million.',
                            ]],
                        ],
                    ]],
                    'usageMetadata' => [
                        'promptTokenCount' => 120,
                        'candidatesTokenCount' => 24,
                    ],
                ]);
            }

            if (str_starts_with($request->url(), 'https://crm.example.com/pipeline')) {
                return Http::response([
                    'currency' => 'AED',
                    'qualified_pipeline' => 4200000,
                    'opportunities' => 18,
                ]);
            }

            return Http::response([], 404);
        });

        $this->actingAs($user)
            ->postJson('/api/ai/chat', [
                'content' => 'What is our qualified CRM pipeline this month?',
            ])
            ->assertOk()
            ->assertJsonPath('message.model', 'gemini-3.5-flash')
            ->assertJsonPath('message.content', 'The qualified CRM pipeline is AED 4.2 million.')
            ->assertJsonPath('message.citations.0.source_name', 'Sales CRM');

        $this->assertSame(2, $geminiRequestCount);
        $this->assertDatabaseHas('messages', [
            'role' => 'assistant',
            'provider' => 'google',
            'response_id' => 'gemini_final_response',
        ]);
        $this->assertDatabaseHas('ai_tool_executions', [
            'tool_name' => 'get_crm_pipeline',
            'status' => 'succeeded',
        ]);
    }

    public function test_unconfigured_provider_fails_without_creating_a_conversation(): void
    {
        config([
            'ai.provider' => 'openai',
            'ai.providers.openai.api_key' => null,
        ]);

        $user = $this->reportingUser();

        $this->actingAs($user)->postJson('/api/ai/chat', [
            'content' => 'Show the sales report.',
        ])->assertStatus(503);

        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_tool_registry_contains_only_the_approved_read_tools(): void
    {
        $this->assertSame([
            'get_sales_report',
            'get_asset_summary',
            'get_procurement_report',
            'get_website_analytics',
            'get_crm_pipeline',
        ], app(ToolRegistry::class)->names());
    }

    public function test_a_user_cannot_read_or_continue_another_users_conversation(): void
    {
        $owner = $this->reportingUser();
        $other = User::factory()->create(['is_active' => true]);
        $other->roles()->attach($owner->roles()->first());
        $conversation = Conversation::create([
            'user_id' => $owner->id,
            'title' => 'Confidential pipeline',
            'status' => 'active',
            'last_message_at' => now(),
        ]);

        $this->actingAs($other)
            ->getJson("/api/ai/conversations/{$conversation->id}")
            ->assertUnprocessable();
        $this->actingAs($other)
            ->postJson('/api/ai/chat', [
                'conversation_id' => $conversation->id,
                'content' => 'Continue.',
            ])
            ->assertUnprocessable();
    }

    public function test_invalid_provider_json_returns_a_controlled_error(): void
    {
        config([
            'ai.provider' => 'openai',
            'ai.providers.openai.api_key' => 'test-key',
        ]);
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response('not-json', 200),
        ]);

        $this->actingAs($this->reportingUser())
            ->postJson('/api/ai/chat', ['content' => 'Show the sales report.'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The OpenAI service returned an invalid response.');
    }

    public function test_insufficient_quota_is_not_retried_and_returns_an_actionable_error(): void
    {
        config([
            'ai.provider' => 'openai',
            'ai.providers.openai.api_key' => 'test-key',
            'ai.provider_retry_attempts' => 2,
            'ai.provider_retry_base_delay_ms' => 0,
        ]);
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'error' => [
                    'type' => 'insufficient_quota',
                    'code' => 'insufficient_quota',
                    'message' => 'Raw provider message must not be returned.',
                ],
            ], 429),
        ]);

        $this->actingAs($this->reportingUser())
            ->postJson('/api/ai/chat', ['content' => 'Show analytics for aboudcar.com.'])
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'insufficient_quota')
            ->assertJsonPath('retryable', false)
            ->assertJsonPath(
                'message',
                'OpenAI API quota is unavailable. Add billing credits or increase the project usage limit, then try again.'
            )
            ->assertJsonMissing(['message' => 'Raw provider message must not be returned.']);

        Http::assertSentCount(1);
    }

    public function test_transient_rate_limit_is_retried_before_succeeding(): void
    {
        config([
            'ai.provider' => 'openai',
            'ai.providers.openai.api_key' => 'test-key',
            'ai.provider_retry_attempts' => 2,
            'ai.provider_retry_base_delay_ms' => 0,
        ]);
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::sequence()
                ->push([
                    'error' => [
                        'type' => 'rate_limit_error',
                        'code' => 'rate_limit_exceeded',
                    ],
                ], 429)
                ->push([
                    'id' => 'resp_after_retry',
                    'output' => [[
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => [[
                            'type' => 'output_text',
                            'text' => 'Analytics are available.',
                        ]],
                    ]],
                ]),
        ]);

        $this->actingAs($this->reportingUser())
            ->postJson('/api/ai/chat', ['content' => 'Show analytics for aboudcar.com.'])
            ->assertOk()
            ->assertJsonPath('message.content', 'Analytics are available.');

        Http::assertSentCount(2);
    }

    public function test_unsupported_provider_is_reported_as_unconfigured(): void
    {
        config(['ai.provider' => 'unsupported-provider']);

        $this->actingAs($this->reportingUser())
            ->getJson('/api/ai/status')
            ->assertOk()
            ->assertJsonPath('configured', false)
            ->assertJsonPath('provider', 'unsupported');
    }

    private function reportingUser(): User
    {
        $permissions = collect([
            ['name' => 'ai.chat', 'label' => 'Use AI chat', 'group' => 'AI'],
            ['name' => 'reports.view', 'label' => 'View reports', 'group' => 'Reports'],
        ])->map(fn ($attributes) => Permission::create($attributes));
        $role = Role::create(['name' => 'analyst', 'label' => 'Analyst']);
        $role->permissions()->attach($permissions->pluck('id'));
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
