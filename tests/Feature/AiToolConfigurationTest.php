<?php

namespace Tests\Feature;

use App\Models\AiCorrection;
use App\Models\AiToolDefinition;
use App\Models\AiToolFailure;
use App\Models\DataSource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Ai\CorrectionMemory;
use App\Services\Ai\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression cover for the reported fault: Freshservice showed as connected
 * under Data Sources, but the assistant replied that it had no ITSM connector.
 *
 * Root cause was a hard-coded allow list in ToolRegistry whose source types
 * excluded `freshservice`, combined with a static prompt that told the model
 * nothing about which sources existed.
 */
class AiToolConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests are about tool configuration, not the identity gates.
        Config::set('security.two_factor.enabled', false);
        Config::set('ai.tool_cache_seconds', 0);
    }

    /* ==============================================================
     * The registry is configuration, not code
     * ============================================================== */

    public function test_the_registry_loads_tools_from_the_database(): void
    {
        AiToolDefinition::query()->delete();

        $this->tool(['name' => 'get_widgets', 'source_types' => ['erp']]);

        $registry = app(ToolRegistry::class);

        $this->assertSame(['get_widgets'], $registry->names());
    }

    public function test_a_disabled_tool_is_not_offered_or_callable(): void
    {
        AiToolDefinition::query()->delete();
        $this->tool(['name' => 'get_widgets', 'is_enabled' => false]);

        $registry = app(ToolRegistry::class);

        $this->assertSame([], $registry->names());
        $this->assertFalse($registry->has('get_widgets'));

        // Still an allow list: an unapproved name is refused, not attempted.
        $this->expectException(\InvalidArgumentException::class);
        $registry->get('get_widgets');
    }

    public function test_a_tool_with_an_unimplemented_handler_is_skipped(): void
    {
        AiToolDefinition::query()->delete();
        $definition = $this->tool(['name' => 'get_widgets']);

        // Simulate a handler removed from code after being configured.
        $definition->forceFill(['handler' => 'handler_that_no_longer_exists'])->save();

        $this->assertSame([], app(ToolRegistry::class)->names());
    }

    public function test_the_itsm_tool_is_seeded_and_covers_freshservice(): void
    {
        $tool = AiToolDefinition::where('name', 'get_itsm_ticket_summary')->first();

        $this->assertNotNull($tool, 'The migration must seed an ITSM tool.');
        $this->assertContains('freshservice', $tool->source_types);
        $this->assertTrue($tool->is_enabled);
        $this->assertContains('freshservice', app(ToolRegistry::class)->reachableSourceTypes());
    }

    public function test_the_five_original_tools_are_preserved(): void
    {
        // Behaviour must not change for the tools that already worked.
        foreach ([
            'get_sales_report', 'get_asset_summary', 'get_procurement_report',
            'get_website_analytics', 'get_crm_pipeline',
        ] as $name) {
            $this->assertDatabaseHas('ai_tools', ['name' => $name, 'is_enabled' => true]);
        }
    }

    /* ==============================================================
     * The UI must not advertise unreachable sources
     * ============================================================== */

    public function test_status_lists_freshservice_once_a_tool_covers_it(): void
    {
        $user = $this->analyst();
        $this->freshserviceSource($user);

        $response = $this->actingAs($user)->getJson('/api/ai/status')->assertOk();

        $types = collect($response->json('sources'))->pluck('type');

        $this->assertContains('freshservice', $types);
        $this->assertSame([], $response->json('unreachable_sources'));
    }

    public function test_status_moves_a_source_to_unreachable_when_no_tool_covers_it(): void
    {
        $user = $this->analyst();
        $this->freshserviceSource($user);

        // Disabling the ITSM tool is exactly the state that caused the bug.
        AiToolDefinition::where('name', 'get_itsm_ticket_summary')->update(['is_enabled' => false]);

        $response = $this->actingAs($user)->getJson('/api/ai/status')->assertOk();

        $this->assertNotContains('freshservice', collect($response->json('sources'))->pluck('type'));
        // Surfaced as a gap rather than silently advertised as available.
        $this->assertContains(
            'freshservice',
            collect($response->json('unreachable_sources'))->pluck('type'),
        );
    }

    /* ==============================================================
     * Admin API
     * ============================================================== */

    public function test_managing_tools_requires_the_integrations_permission(): void
    {
        $analyst = $this->analyst();

        $this->actingAs($analyst)->getJson('/api/admin/ai-tools')->assertForbidden();
        $this->actingAs($analyst)->postJson('/api/admin/ai-tools', [])->assertForbidden();
    }

    public function test_an_administrator_can_create_a_tool(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->postJson('/api/admin/ai-tools', [
                'name' => 'get_hr_headcount',
                'label' => 'HR headcount',
                'description' => 'Retrieve grounded headcount and joiner or leaver counts from an approved HR source.',
                'handler' => 'generic_http',
                'source_types' => ['erp'],
                'is_enabled' => true,
                'sort_order' => 70,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('ai_tools', ['name' => 'get_hr_headcount']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'ai.tool.created']);
        $this->assertTrue(app(ToolRegistry::class)->has('get_hr_headcount'));
    }

    public function test_an_unimplemented_handler_is_rejected(): void
    {
        $admin = $this->administrator();

        // The security boundary: an admin selects an approved handler and cannot
        // introduce new fetch behaviour through the form.
        $this->actingAs($admin)
            ->postJson('/api/admin/ai-tools', [
                'name' => 'get_anything',
                'label' => 'Anything',
                'description' => 'Retrieve absolutely anything from any endpoint that I feel like naming here.',
                'handler' => 'shell_exec',
                'source_types' => ['erp'],
                'is_enabled' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('handler');
    }

    public function test_an_unregistered_source_type_is_rejected(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->postJson('/api/admin/ai-tools', [
                'name' => 'get_mystery',
                'label' => 'Mystery',
                'description' => 'Retrieve grounded data from a source type that is not registered anywhere.',
                'handler' => 'generic_http',
                'source_types' => ['not_a_real_type'],
                'is_enabled' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source_types.0');
    }

    public function test_a_vague_description_is_rejected(): void
    {
        $admin = $this->administrator();

        // A vague description is why a tool never gets called, so it is treated
        // as a configuration error rather than a style preference.
        $this->actingAs($admin)
            ->postJson('/api/admin/ai-tools', [
                'name' => 'get_stuff',
                'label' => 'Stuff',
                'description' => 'Gets stuff.',
                'handler' => 'generic_http',
                'source_types' => ['erp'],
                'is_enabled' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('description');
    }

    public function test_toggling_a_tool_is_audited(): void
    {
        $admin = $this->administrator();
        $tool = AiToolDefinition::where('name', 'get_itsm_ticket_summary')->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/admin/ai-tools/{$tool->id}/toggle", ['is_enabled' => false])
            ->assertOk();

        $this->assertFalse($tool->fresh()->is_enabled);
        $this->assertDatabaseHas('audit_logs', ['event' => 'ai.tool.toggled']);
    }

    public function test_the_index_flags_connected_sources_no_tool_covers(): void
    {
        $admin = $this->administrator();
        $this->freshserviceSource($admin);
        AiToolDefinition::where('name', 'get_itsm_ticket_summary')->update(['is_enabled' => false]);

        $uncovered = $this->actingAs($admin)
            ->getJson('/api/admin/ai-tools')
            ->assertOk()
            ->json('uncovered_sources');

        $this->assertContains('freshservice', collect($uncovered)->pluck('type'));
    }

    /* ==============================================================
     * ITSM retrieval
     * ============================================================== */

    public function test_the_itsm_tool_returns_ticket_counts(): void
    {
        Http::fake([
            '*/api/v2/ticket_form_fields*' => Http::response(['ticket_fields' => []]),
            '*/api/v2/tickets/filter*' => Http::response(['tickets' => [], 'total' => 42]),
            '*/api/v2/agents*' => Http::response(['agents' => []]),
            '*/api/v2/groups*' => Http::response(['groups' => []]),
        ]);

        $user = $this->analyst();
        $source = $this->freshserviceSource($user);

        $result = app(ToolRegistry::class)
            ->get('get_itsm_ticket_summary')
            ->execute($user, [
                'data_source_id' => $source->id,
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
                'limit' => 50,
            ]);

        // Aggregates, not a raw page of ticket objects.
        $this->assertArrayHasKey('totals', $result->data);
        $this->assertArrayHasKey('by_status', $result->data);
        $this->assertArrayHasKey('by_type', $result->data);
        $this->assertArrayHasKey('period', $result->data);
        $this->assertSame($source->id, $result->citations[0]['source_id']);
    }

    public function test_a_single_day_range_is_inclusive_of_that_day(): void
    {
        // Freshservice date comparisons are exclusive, so comparing against
        // date_from directly dropped every ticket created on it — asking for
        // "today" returned zero. The bound must be shifted outward.
        Http::fake([
            '*/api/v2/ticket_form_fields*' => Http::response(['ticket_fields' => []]),
            '*/api/v2/tickets/filter*' => Http::response(['tickets' => [], 'total' => 5]),
            '*/api/v2/agents*' => Http::response(['agents' => []]),
            '*/api/v2/groups*' => Http::response(['groups' => []]),
        ]);

        $user = $this->analyst();
        $source = $this->freshserviceSource($user);

        app(ToolRegistry::class)->get('get_itsm_ticket_summary')->execute($user, [
            'data_source_id' => $source->id,
            'date_from' => '2026-07-31',
            'date_to' => '2026-07-31',
            'limit' => 50,
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'tickets/filter')) {
                return false;
            }

            $query = urldecode($request->url());

            // Lower bound one day before, upper bound one day after.
            return str_contains($query, "created_at:>'2026-07-30'")
                && str_contains($query, "created_at:<'2026-08-01'");
        });
    }

    public function test_a_missing_source_records_a_failure_with_a_clear_reason(): void
    {
        $user = $this->analyst();
        // No Freshservice source exists at all.

        try {
            app(ToolRegistry::class)->get('get_itsm_ticket_summary')->execute($user, [
                'data_source_id' => null,
                'date_from' => null,
                'date_to' => null,
                'limit' => 50,
            ]);
            $this->fail('Expected the tool to refuse.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('No connected data source', $exception->getMessage());
        }

        $failure = AiToolFailure::where('tool_name', 'get_itsm_ticket_summary')->first();

        $this->assertNotNull($failure);
        $this->assertSame(AiToolFailure::REASON_NO_SOURCE, $failure->reason);
    }

    public function test_a_visible_source_that_the_user_cannot_read_says_so_distinctly(): void
    {
        $owner = $this->analyst();
        $this->freshserviceSource($owner, ['allowed_roles' => ['nobody']]);

        // A different user with no access to that source.
        $other = User::factory()->create(['is_active' => true, 'department' => 'Sales']);
        $other->roles()->attach(Role::firstOrCreate(['name' => 'analyst'], ['label' => 'Analyst']));
        $this->grant('analyst', ['reports.view']);

        try {
            app(ToolRegistry::class)->get('get_itsm_ticket_summary')->execute($other->fresh(), [
                'data_source_id' => null,
                'date_from' => null,
                'date_to' => null,
                'limit' => 50,
            ]);
            $this->fail('Expected the tool to refuse.');
        } catch (\RuntimeException $exception) {
            // Must not be conflated with "no connector exists" — that
            // conflation is what sent the user hunting the wrong problem.
            $this->assertStringContainsString('not authorized', $exception->getMessage());
        }

        $this->assertSame(
            AiToolFailure::REASON_NOT_AUTHORIZED,
            AiToolFailure::where('tool_name', 'get_itsm_ticket_summary')->first()?->reason,
        );
    }

    /* ==============================================================
     * Correction memory
     * ============================================================== */

    public function test_a_reported_correction_starts_pending_and_is_not_injected(): void
    {
        $user = $this->analyst();

        $this->actingAs($user)
            ->postJson('/api/ai/corrections', [
                'question' => 'How many request tickets today?',
                'incorrect_answer' => 'I have no ITSM connector.',
                'correction' => 'Freshservice ITSM is connected; use the ITSM ticket summary tool.',
            ])
            ->assertCreated();

        $correction = AiCorrection::firstOrFail();
        $this->assertSame('pending', $correction->status);

        // Crucially: a pending correction must not reach the prompt, or any user
        // could inject trusted guidance for everyone.
        $relevant = app(CorrectionMemory::class)->relevantTo('How many request tickets today?');
        $this->assertCount(0, $relevant);
    }

    public function test_an_approved_correction_is_injected_for_a_relevant_question(): void
    {
        $admin = $this->administrator();

        $correction = AiCorrection::create([
            'question' => 'How many request tickets today from ITSM?',
            'correction' => 'Freshservice ITSM is connected. Use get_itsm_ticket_summary for ticket counts.',
            'topic' => 'itsm',
            'status' => 'pending',
            'reported_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/admin/ai-tools/corrections/{$correction->id}/review", [
                'status' => 'approved',
            ])
            ->assertOk();

        $memory = app(CorrectionMemory::class);
        $relevant = $memory->relevantTo('how many request tickets today from itsm');

        $this->assertCount(1, $relevant);
        $this->assertStringContainsString(
            'get_itsm_ticket_summary',
            $memory->asPromptFragment($relevant),
        );
    }

    public function test_an_approved_correction_is_not_injected_for_an_unrelated_question(): void
    {
        $admin = $this->administrator();

        AiCorrection::create([
            'question' => 'How many request tickets today from ITSM?',
            'correction' => 'Freshservice ITSM is connected.',
            'topic' => 'itsm',
            'status' => 'approved',
            'reported_by' => $admin->id,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        $relevant = app(CorrectionMemory::class)
            ->relevantTo('what was procurement spend last quarter');

        $this->assertCount(0, $relevant);
    }

    public function test_a_rejected_correction_never_applies(): void
    {
        $admin = $this->administrator();

        $correction = AiCorrection::create([
            'question' => 'ticket counts',
            'correction' => 'Something an administrator judged wrong.',
            'status' => 'pending',
            'reported_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/admin/ai-tools/corrections/{$correction->id}/review", [
                'status' => 'rejected',
            ])
            ->assertOk();

        $this->assertCount(0, app(CorrectionMemory::class)->relevantTo('ticket counts'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'ai.correction.reviewed']);
    }

    public function test_reviewing_a_correction_requires_the_admin_permission(): void
    {
        $analyst = $this->analyst();
        $correction = AiCorrection::create([
            'question' => 'q',
            'correction' => 'c',
            'status' => 'pending',
            'reported_by' => $analyst->id,
        ]);

        $this->actingAs($analyst)
            ->postJson("/api/admin/ai-tools/corrections/{$correction->id}/review", ['status' => 'approved'])
            ->assertForbidden();

        $this->assertSame('pending', $correction->fresh()->status);
    }

    public function test_correction_text_is_encrypted_at_rest(): void
    {
        $admin = $this->administrator();

        AiCorrection::create([
            'question' => 'a very distinctive question string',
            'correction' => 'a very distinctive correction string',
            'status' => 'approved',
            'reported_by' => $admin->id,
        ]);

        $raw = \DB::table('ai_corrections')->value('correction');

        $this->assertStringNotContainsString('distinctive correction', (string) $raw);
    }

    /* ==============================================================
     * Result cache
     * ============================================================== */

    public function test_a_cached_result_is_labelled_as_reused(): void
    {
        Config::set('ai.tool_cache_seconds', 300);

        Http::fake([
            '*/api/v2/ticket_form_fields*' => Http::response(['ticket_fields' => []]),
            '*/api/v2/tickets/filter*' => Http::response(['tickets' => [], 'total' => 7]),
            '*/api/v2/agents*' => Http::response(['agents' => []]),
            '*/api/v2/groups*' => Http::response(['groups' => []]),
        ]);

        $user = $this->analyst();
        $source = $this->freshserviceSource($user);
        $tool = app(ToolRegistry::class)->get('get_itsm_ticket_summary');
        $arguments = [
            'data_source_id' => $source->id,
            'date_from' => '2026-07-31',
            'date_to' => '2026-07-31',
            'limit' => 50,
        ];

        $first = $tool->execute($user, $arguments);
        $second = $tool->execute($user, $arguments);

        $this->assertArrayNotHasKey('served_from_cache', $first->summary);
        // The model must be able to tell the user the figure was reused, and when
        // it was originally fetched.
        $this->assertTrue($second->summary['served_from_cache']);
        $this->assertArrayHasKey('originally_retrieved_at', $second->summary);
    }

    /* ==============================================================
     * Helpers
     * ============================================================== */

    private function tool(array $attributes = []): AiToolDefinition
    {
        return AiToolDefinition::create([
            'name' => 'get_test_tool',
            'label' => 'Test tool',
            'description' => 'Retrieve grounded test data from an approved source for the purposes of testing.',
            'handler' => 'generic_http',
            'source_types' => ['erp'],
            'is_enabled' => true,
            'sort_order' => 1,
            ...$attributes,
        ]);
    }

    private function freshserviceSource(User $owner, array $settings = []): DataSource
    {
        $source = DataSource::create([
            'name' => 'Freshservice ITSM',
            'type' => 'freshservice',
            'base_url' => 'https://company.freshservice.com',
            'status' => 'connected',
            'owner_id' => $owner->id,
            'settings' => ['data_path' => '/api/v2/tickets', ...$settings],
        ]);

        $source->apiConfiguration()->create([
            'auth_type' => 'basic',
            'credentials' => ['username' => 'fake-key', 'password' => 'X'],
            'timeout_seconds' => 30,
            'retry_count' => 1,
        ]);

        return $source->fresh();
    }

    private function analyst(): User
    {
        $this->grant('analyst', ['reports.view']);

        $user = User::factory()->create([
            'is_active' => true,
            'department' => 'Information Technology',
            'password_changed_at' => now(),
        ]);
        $user->roles()->attach(Role::where('name', 'analyst')->firstOrFail());

        return $user->fresh();
    }

    private function administrator(): User
    {
        $this->grant('administrator', ['reports.view', 'integrations.manage']);

        $user = User::factory()->create([
            'is_active' => true,
            'department' => 'Information Technology',
            'password_changed_at' => now(),
        ]);
        $user->roles()->attach(Role::where('name', 'administrator')->firstOrFail());

        return $user->fresh();
    }

    private function grant(string $roleName, array $permissions): void
    {
        $role = Role::firstOrCreate(['name' => $roleName], ['label' => ucfirst($roleName)]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'group' => 'Testing'],
            );

            if (! $role->permissions()->where('permissions.id', $permission->id)->exists()) {
                $role->permissions()->attach($permission);
            }
        }
    }
}
