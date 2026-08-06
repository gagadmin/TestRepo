<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Report;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdvancedAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_analyst_can_generate_governed_advanced_insights(): void
    {
        $analyst = $this->user(['analytics.view', 'analytics.run']);
        $report = $this->reportWithSnapshot($analyst);

        $response = $this->actingAs($analyst)
            ->postJson("/api/analytics/reports/{$report->id}")
            ->assertOk()
            ->assertJsonFragment(['type' => 'trend'])
            ->assertJsonFragment(['type' => 'anomaly'])
            ->assertJsonFragment(['type' => 'forecast'])
            ->assertJsonFragment(['type' => 'recommendation']);

        $this->assertGreaterThanOrEqual(6, count($response->json('data')));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'analytics.generated',
            'auditable_id' => (string) $report->id,
        ]);

        $rawPayload = DB::table('analytics_insights')->where('type', 'forecast')->value('payload');
        $this->assertStringNotContainsString('linear_regression', $rawPayload);
    }

    public function test_analytics_index_only_returns_reports_visible_to_the_user(): void
    {
        $owner = $this->user(['analytics.view', 'analytics.run'], 'analyst');
        $viewer = $this->user(['analytics.view'], 'manager');
        $privateReport = $this->reportWithSnapshot($owner);

        $this->actingAs($owner)
            ->postJson("/api/analytics/reports/{$privateReport->id}")
            ->assertOk();

        $this->actingAs($viewer)
            ->getJson('/api/analytics')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonCount(0, 'reports');
    }

    public function test_view_only_user_cannot_generate_analytics(): void
    {
        $owner = $this->user(['analytics.view'], 'manager');
        $report = $this->reportWithSnapshot($owner);

        $this->actingAs($owner)
            ->postJson("/api/analytics/reports/{$report->id}")
            ->assertForbidden();
    }

    public function test_snapshot_requires_sufficient_numeric_history(): void
    {
        $analyst = $this->user(['analytics.view', 'analytics.run']);
        $report = Report::create([
            'user_id' => $analyst->id,
            'name' => 'Sparse report',
            'type' => 'custom',
            'visibility' => 'private',
            'definition' => [
                'columns' => [['key' => 'label', 'label' => 'Label', 'type' => 'text']],
            ],
            'last_generated_at' => now(),
        ]);
        $report->snapshots()->create([
            'generated_by' => $analyst->id,
            'data' => [['label' => 'Only one']],
            'summary' => [],
            'citations' => [],
            'row_count' => 1,
            'generated_at' => now(),
        ]);

        $this->actingAs($analyst)
            ->postJson("/api/analytics/reports/{$report->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'At least three rows and one numeric metric are required for advanced analytics.');
    }

    private function user(array $permissions, string $roleName = 'analyst'): User
    {
        $role = Role::create(['name' => $roleName, 'label' => ucfirst($roleName)]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'group' => 'Analytics']
            );
            $role->permissions()->attach($permission);
        }

        $user = User::factory()->create(['is_active' => true, 'department' => 'Finance']);
        $user->roles()->attach($role);

        return $user;
    }

    private function reportWithSnapshot(User $owner): Report
    {
        $report = Report::create([
            'user_id' => $owner->id,
            'name' => 'Advanced revenue analysis',
            'type' => 'financial_overview',
            'visibility' => 'private',
            'definition' => [
                'columns' => [
                    ['key' => 'period', 'label' => 'Period', 'type' => 'date'],
                    ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'currency'],
                    ['key' => 'orders', 'label' => 'Orders', 'type' => 'number'],
                ],
            ],
            'last_generated_at' => now(),
        ]);
        $report->snapshots()->create([
            'generated_by' => $owner->id,
            'data' => [
                ['period' => '2026-01', 'revenue' => 100, 'orders' => 10],
                ['period' => '2026-02', 'revenue' => 110, 'orders' => 11],
                ['period' => '2026-03', 'revenue' => 105, 'orders' => 12],
                ['period' => '2026-04', 'revenue' => 108, 'orders' => 13],
                ['period' => '2026-05', 'revenue' => 600, 'orders' => 14],
            ],
            'summary' => [],
            'citations' => [],
            'row_count' => 5,
            'generated_at' => now(),
        ]);

        return $report;
    }
}
