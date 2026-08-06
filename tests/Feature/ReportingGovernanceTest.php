<?php

namespace Tests\Feature;

use App\Models\Dashboard;
use App\Models\DataSource;
use App\Models\Permission;
use App\Models\Report;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReportingGovernanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_analysts_cannot_publish_enterprise_reports(): void
    {
        $analyst = $this->user('analyst', 'Finance', ['reports.view', 'reports.create']);

        $this->actingAs($analyst)
            ->postJson('/api/reports', $this->reportPayload('enterprise'))
            ->assertForbidden();
    }

    public function test_department_dashboards_are_isolated_but_executives_can_view_them(): void
    {
        $manager = $this->user('manager', 'Finance', ['dashboards.view', 'reports.view']);
        $executive = $this->user('executive', 'Executive Office', ['dashboards.view', 'reports.view']);
        $finance = Dashboard::create([
            'name' => 'Finance Dashboard',
            'slug' => 'finance',
            'department' => 'Finance',
            'visibility' => 'department',
            'layout' => ['allowed_roles' => ['executive']],
            'is_active' => true,
        ]);
        $sales = Dashboard::create([
            'name' => 'Sales Dashboard',
            'slug' => 'sales',
            'department' => 'Sales',
            'visibility' => 'department',
            'layout' => ['allowed_roles' => ['executive']],
            'is_active' => true,
        ]);

        $this->actingAs($manager)->getJson('/api/dashboards')
            ->assertOk()
            ->assertJsonFragment(['slug' => $finance->slug])
            ->assertJsonMissing(['slug' => $sales->slug]);
        $this->actingAs($executive)->getJson('/api/dashboards')
            ->assertOk()
            ->assertJsonFragment(['slug' => $finance->slug])
            ->assertJsonFragment(['slug' => $sales->slug]);
    }

    public function test_report_generation_rejects_an_unauthorized_source(): void
    {
        $owner = $this->user('executive', 'Executive Office', ['reports.view']);
        $analyst = $this->user('analyst', 'Finance', ['reports.view', 'reports.create']);
        $source = $this->source($owner, ['executive']);
        $report = $this->report($analyst, $source);

        $this->actingAs($analyst)
            ->postJson("/api/reports/{$report->id}/generate")
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'You are not authorized to use the assigned data source.']);
    }

    public function test_snapshots_project_mask_and_encrypt_source_data(): void
    {
        Http::fake([
            'https://erp.example.com/api/reports*' => Http::response([
                'rows' => [[
                    'email' => 'jacob@example.com',
                    'revenue' => 425000,
                    'api_token' => 'top-secret-token',
                    'hidden_national_id' => '784-0000-0000000-0',
                ]],
            ]),
        ]);
        $analyst = $this->user('analyst', 'Finance', ['reports.view', 'reports.create']);
        $source = $this->source($analyst, ['analyst']);
        $report = $this->report($analyst, $source, [
            ['key' => 'email', 'label' => 'Email', 'type' => 'text', 'mask' => 'email'],
            ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'currency'],
            ['key' => 'api_token', 'label' => 'Token', 'type' => 'text'],
        ]);

        $response = $this->actingAs($analyst)
            ->postJson("/api/reports/{$report->id}/generate")
            ->assertOk()
            ->assertJsonPath('data.rows.0.email', 'j***@example.com')
            ->assertJsonPath('data.rows.0.api_token', '[REDACTED]')
            ->assertJsonMissing(['hidden_national_id' => '784-0000-0000000-0']);

        $raw = DB::table('report_snapshots')->where('report_id', $report->id)->value('data');
        $this->assertStringNotContainsString('jacob@example.com', $raw);
        $this->assertStringNotContainsString('top-secret-token', $raw);
        $this->assertNull(json_decode($raw, true));
        $this->assertSame(425000, $report->fresh()->latestSnapshot->data[0]['revenue']);
        $this->assertNotEmpty($response->json('data.citations'));
    }

    public function test_expired_snapshots_are_purged_by_retention_command(): void
    {
        $user = $this->user('analyst', 'Finance', ['reports.view']);
        $report = $this->report($user);
        $report->snapshots()->create([
            'generated_by' => $user->id,
            'data' => [['value' => 1]],
            'row_count' => 1,
            'generated_at' => now()->subDays(31),
        ]);
        $report->update(['last_generated_at' => now()->subDays(31)]);

        $this->artisan('reports:purge-snapshots', ['--days' => 30])
            ->expectsOutput('Purged 1 expired report snapshot(s).')
            ->assertSuccessful();

        $this->assertDatabaseMissing('report_snapshots', ['report_id' => $report->id]);
        $this->assertNull($report->fresh()->last_generated_at);
    }

    public function test_security_headers_are_applied(): void
    {
        config()->set('app.vite_dev_server_url', 'http://127.0.0.1:5173');

        $response = $this->get('/')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');

        $policy = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' 'unsafe-eval' http://127.0.0.1:5173", $policy);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline' http://127.0.0.1:5173", $policy);
        $this->assertStringContainsString("font-src 'self' data: http://127.0.0.1:5173", $policy);
        $this->assertStringContainsString("connect-src 'self' http://127.0.0.1:5173 ws://127.0.0.1:5173", $policy);
    }

    public function test_production_csp_does_not_allow_the_vite_development_server(): void
    {
        config()->set('app.vite_dev_server_url', 'http://127.0.0.1:5173');
        $this->app->detectEnvironment(fn (): string => 'production');

        $policy = $this->get('/')
            ->assertOk()
            ->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('127.0.0.1:5173', $policy);
        $this->assertStringNotContainsString("'unsafe-eval'", $policy);
        $this->assertStringContainsString("script-src 'self'", $policy);
        $this->assertStringContainsString("font-src 'self' data:", $policy);
    }

    private function reportPayload(string $visibility): array
    {
        return [
            'name' => 'Governed report',
            'type' => 'custom',
            'description' => 'Governance test',
            'visibility' => $visibility,
            'definition' => [
                'source_id' => null,
                'columns' => [
                    ['key' => 'label', 'label' => 'Label', 'type' => 'text'],
                    ['key' => 'value', 'label' => 'Value', 'type' => 'number'],
                ],
                'chart' => [
                    'type' => 'bar',
                    'category_key' => 'label',
                    'value_key' => 'value',
                ],
            ],
        ];
    }

    private function user(string $roleName, string $department, array $permissions): User
    {
        $role = Role::firstOrCreate(
            ['name' => $roleName],
            ['label' => ucfirst($roleName)]
        );

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'group' => 'Test']
            );
            $role->permissions()->syncWithoutDetaching($permission);
        }

        $user = User::factory()->create([
            'department' => $department,
            'is_active' => true,
        ]);
        $user->roles()->attach($role);

        return $user;
    }

    private function source(User $owner, array $allowedRoles): DataSource
    {
        $source = DataSource::create([
            'name' => 'Governed ERP',
            'type' => 'erp',
            'base_url' => 'https://erp.example.com',
            'status' => 'connected',
            'owner_id' => $owner->id,
            'settings' => [
                'data_path' => '/api/reports',
                'allowed_roles' => $allowedRoles,
                'allowed_departments' => [],
            ],
        ]);
        $source->apiConfiguration()->create([
            'auth_type' => 'none',
            'timeout_seconds' => 30,
            'retry_count' => 1,
        ]);

        return $source;
    }

    private function report(User $owner, ?DataSource $source = null, ?array $columns = null): Report
    {
        return Report::create([
            'user_id' => $owner->id,
            'name' => 'Governed report',
            'type' => 'custom',
            'visibility' => 'private',
            'definition' => [
                'source_id' => $source?->id,
                'columns' => $columns ?? [
                    ['key' => 'value', 'label' => 'Value', 'type' => 'number'],
                ],
                'chart' => ['type' => 'bar', 'category_key' => 'value', 'value_key' => 'value'],
            ],
        ]);
    }
}
