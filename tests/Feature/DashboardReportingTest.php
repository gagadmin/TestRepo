<?php

namespace Tests\Feature;

use App\Models\Dashboard;
use App\Models\DataSource;
use App\Models\Permission;
use App\Models\Report;
use App\Models\Role;
use App\Models\User;
use App\Services\Integrations\GoogleSearchConsoleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class DashboardReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_view_a_dashboard_with_snapshot_data(): void
    {
        $user = $this->userWithPermissions(['dashboards.view', 'reports.view']);
        [$report, $dashboard] = $this->reportAndDashboard($user);
        $report->snapshots()->create([
            'generated_by' => $user->id,
            'data' => [
                ['period' => '2026-07-01', 'region' => 'North', 'revenue' => 120000],
                ['period' => '2026-07-02', 'region' => 'South', 'revenue' => 95000],
            ],
            'summary' => ['numeric_totals' => ['revenue' => 215000]],
            'citations' => [['source_name' => 'QA ERP']],
            'row_count' => 2,
            'generated_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson("/api/dashboards/{$dashboard->slug}?region=North")
            ->assertOk()
            ->assertJsonPath('data.reports.0.rows.0.revenue', 120000)
            ->assertJsonCount(1, 'data.reports.0.rows')
            ->assertJsonPath('data.reports.0.citations.0.source_name', 'QA ERP');
    }

    public function test_private_report_is_not_visible_to_another_user(): void
    {
        $owner = $this->userWithPermissions(['reports.view']);
        $viewer = $this->userWithPermissions(['reports.view']);
        $report = $this->makeReport($owner, 'private');

        $this->actingAs($viewer)
            ->getJson("/api/reports/{$report->id}")
            ->assertNotFound();
    }

    public function test_report_refresh_retrieves_rows_and_records_source_citations(): void
    {
        Http::fake([
            'https://erp.example.com/api/reports*' => Http::response([
                'rows' => [
                    ['period' => '2026-07', 'region' => 'Dubai', 'revenue' => 425000],
                ],
            ]),
        ]);
        $user = $this->userWithPermissions(['reports.view', 'reports.create']);
        $source = DataSource::create([
            'name' => 'Enterprise ERP',
            'type' => 'erp',
            'base_url' => 'https://erp.example.com',
            'status' => 'connected',
            'owner_id' => $user->id,
            'settings' => ['data_path' => '/api/reports'],
        ]);
        $source->apiConfiguration()->create([
            'auth_type' => 'none',
            'timeout_seconds' => 30,
            'retry_count' => 1,
        ]);
        $report = $this->makeReport($user);
        $report->update(['definition' => [
            ...$report->definition,
            'source_id' => $source->id,
        ]]);

        $this->actingAs($user)
            ->postJson("/api/reports/{$report->id}/generate", ['region' => 'Dubai'])
            ->assertOk()
            ->assertJsonPath('data.rows.0.revenue', 425000)
            ->assertJsonPath('data.citations.0.source_name', 'Enterprise ERP');

        $this->assertDatabaseHas('report_snapshots', [
            'report_id' => $report->id,
            'row_count' => 1,
        ]);
        Http::assertSent(fn ($request) => $request->url() === 'https://erp.example.com/api/reports?report_type=sales&region=Dubai');
    }

    public function test_report_can_be_exported_as_real_xlsx_and_pdf_files(): void
    {
        $user = $this->userWithPermissions(['reports.view']);
        $report = $this->makeReport($user);
        $report->snapshots()->create([
            'generated_by' => $user->id,
            'data' => [
                ['period' => '2026-07', 'region' => 'Dubai', 'revenue' => 425000.50],
                ['period' => '2026-08', 'region' => 'Abu Dhabi', 'revenue' => 390000],
            ],
            'summary' => ['numeric_totals' => ['revenue' => 815000.50]],
            'citations' => [['source_name' => 'QA ERP']],
            'row_count' => 2,
            'generated_at' => now(),
        ]);

        $xlsx = $this->actingAs($user)->get("/api/reports/{$report->id}/export/xlsx");
        $xlsx->assertOk()->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
        $this->assertStringStartsWith('PK', $xlsx->getContent());

        $pdf = $this->actingAs($user)->get("/api/reports/{$report->id}/export/pdf");
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event' => 'report.exported',
            'auditable_id' => (string) $report->id,
        ]);
    }

    public function test_website_report_can_generate_from_google_search_console(): void
    {
        $user = $this->userWithPermissions(['reports.view', 'reports.create']);
        $source = DataSource::create([
            'name' => 'Aboudcar Search Console',
            'type' => 'google_search_console',
            'base_url' => 'https://www.googleapis.com/webmasters/v3',
            'status' => 'connected',
            'owner_id' => $user->id,
            'settings' => ['site_url' => 'https://www.aboudcar.com/'],
        ]);
        $report = Report::create([
            'user_id' => $user->id,
            'name' => 'Aboudcar search performance',
            'type' => 'website_analytics',
            'visibility' => 'private',
            'definition' => [
                'source_id' => $source->id,
                'columns' => [
                    ['key' => 'query', 'label' => 'Query', 'type' => 'text'],
                    ['key' => 'clicks', 'label' => 'Clicks', 'type' => 'number'],
                    ['key' => 'impressions', 'label' => 'Impressions', 'type' => 'number'],
                    ['key' => 'ctr', 'label' => 'CTR', 'type' => 'percentage'],
                    ['key' => 'position', 'label' => 'Position', 'type' => 'number'],
                ],
            ],
        ]);

        $this->mock(
            GoogleSearchConsoleService::class,
            fn (MockInterface $mock) => $mock->shouldReceive('analytics')
                ->once()
                ->with(
                    [
                        'report_type' => 'website_analytics',
                        'dimension' => 'query',
                        'date_from' => '2026-07-01',
                        'date_to' => '2026-07-27',
                    ],
                    'https://www.aboudcar.com/',
                )
                ->andReturn([
                    'rows' => [[
                        'query' => 'used cars dubai',
                        'clicks' => 8,
                        'impressions' => 100,
                        'ctr' => 8.0,
                        'position' => 2.5,
                    ]],
                    'summary' => [
                        'site_url' => 'https://www.aboudcar.com/',
                        'dimension' => 'query',
                        'clicks' => 8,
                        'impressions' => 100,
                    ],
                ]),
        );

        $this->actingAs($user)
            ->postJson("/api/reports/{$report->id}/generate", [
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-27',
            ])
            ->assertOk()
            ->assertJsonPath('data.rows.0.query', 'used cars dubai')
            ->assertJsonPath('data.rows.0.ctr', 8)
            ->assertJsonPath('data.citations.0.source_type', 'google_search_console');
    }

    public function test_dashboard_user_can_select_a_search_console_analytics_breakdown(): void
    {
        $user = $this->userWithPermissions(['dashboards.view']);
        $source = DataSource::create([
            'name' => 'Aboudcar Search Console',
            'type' => 'google_search_console',
            'base_url' => 'https://www.googleapis.com/webmasters/v3',
            'status' => 'connected',
            'owner_id' => $user->id,
            'settings' => ['site_url' => 'https://www.aboudcar.com/'],
        ]);

        $this->mock(
            GoogleSearchConsoleService::class,
            fn (MockInterface $mock) => $mock->shouldReceive('analytics')
                ->once()
                ->with(
                    ['date_from' => '2026-07-01', 'date_to' => '2026-07-27', 'dimension' => 'page', 'limit' => 25],
                    'https://www.aboudcar.com/',
                )
                ->andReturn([
                    'rows' => [[
                        'page' => 'https://www.aboudcar.com/cars',
                        'clicks' => 18,
                        'impressions' => 400,
                        'ctr' => 4.5,
                        'position' => 3.2,
                    ]],
                    'summary' => [
                        'site_url' => 'https://www.aboudcar.com/',
                        'date_from' => '2026-07-01',
                        'date_to' => '2026-07-27',
                        'dimension' => 'page',
                        'clicks' => 18,
                        'impressions' => 400,
                        'ctr' => 4.5,
                        'position' => 3.2,
                    ],
                ]),
        );

        $this->actingAs($user)
            ->getJson("/api/dashboards/search-console?data_source_id={$source->id}&date_from=2026-07-01&date_to=2026-07-27&dimension=page&limit=25")
            ->assertOk()
            ->assertJsonPath('data.rows.0.page', 'https://www.aboudcar.com/cars')
            ->assertJsonPath('data.summary.dimension', 'page')
            ->assertJsonPath('data.citation.source_name', 'Aboudcar Search Console');
    }

    public function test_dashboard_user_can_view_bounded_freshservice_ticket_analytics(): void
    {
        CarbonImmutable::setTestNow('2026-07-29 12:00:00');
        $user = $this->userWithPermissions(['dashboards.view']);
        $source = DataSource::create([
            'name' => 'Freshservice ITSM',
            'type' => 'freshservice',
            'base_url' => 'https://company.freshservice.com',
            'status' => 'connected',
            'owner_id' => $user->id,
            'settings' => ['on_hold_status_ids' => [3, 8]],
        ]);
        $source->apiConfiguration()->create([
            'auth_type' => 'basic',
            'encrypted_credentials' => ['username' => 'fake-api-key', 'password' => 'X'],
            'timeout_seconds' => 30,
            'retry_count' => 0,
        ]);

        Http::fake(function ($request) {
            if ($request->url() === 'https://company.freshservice.com/api/v2/ticket_form_fields') {
                return Http::response(['ticket_fields' => [
                    ['name' => 'status', 'choices' => [
                        ['id' => 2, 'value' => 'Open'],
                        ['id' => 3, 'value' => 'Pending'],
                        ['id' => 4, 'value' => 'Resolved'],
                        ['id' => 5, 'value' => 'Closed'],
                        ['id' => 6, 'value' => 'Assigned'],
                        ['id' => 8, 'value' => 'Awaiting Approval'],
                    ]],
                    ['name' => 'priority', 'choices' => [
                        ['id' => 1, 'value' => 'Low'],
                        ['id' => 2, 'value' => 'Medium'],
                        ['id' => 3, 'value' => 'High'],
                        ['id' => 4, 'value' => 'Urgent'],
                    ]],
                ]]);
            }

            if (str_contains($request->url(), '/api/v2/tickets/filter')) {
                $tickets = [
                    2 => [[
                        'id' => 1,
                        'status' => 2,
                        'priority' => 4,
                        'responder_id' => 10,
                        'group_id' => 20,
                        'due_by' => '2026-07-29T15:00:00Z',
                        'is_escalated' => false,
                        'fr_escalated' => false,
                        'created_at' => '2026-07-28T08:00:00Z',
                    ]],
                    3 => [[
                        'id' => 2,
                        'status' => 3,
                        'priority' => 3,
                        'responder_id' => null,
                        'group_id' => 20,
                        'due_by' => '2026-07-28T08:00:00Z',
                        'is_escalated' => true,
                        'fr_escalated' => false,
                        'created_at' => '2026-07-27T08:00:00Z',
                    ]],
                    4 => [[
                        'id' => 3,
                        'status' => 4,
                        'priority' => 2,
                        'responder_id' => 10,
                        'group_id' => 20,
                        'due_by' => '2026-07-27T08:00:00Z',
                        'is_escalated' => false,
                        'fr_escalated' => false,
                        'created_at' => '2026-07-25T08:00:00Z',
                    ]],
                    5 => [],
                    6 => [[
                        'id' => 5,
                        'status' => 6,
                        'priority' => 4,
                        'responder_id' => 10,
                        'group_id' => 20,
                        'due_by' => '2026-07-28T08:00:00Z',
                        'is_escalated' => true,
                        'fr_escalated' => false,
                        'created_at' => '2026-07-26T08:00:00Z',
                    ]],
                    8 => [[
                        'id' => 4,
                        'status' => 8,
                        'priority' => 1,
                        'responder_id' => 10,
                        'group_id' => 20,
                        'due_by' => '2026-07-29T08:00:00Z',
                        'is_escalated' => false,
                        'fr_escalated' => false,
                        'created_at' => '2026-07-26T08:00:00Z',
                    ]],
                ];
                preg_match('/status%3A(\\d+)|status:(\\d+)/', $request->url(), $matches);
                $status = (int) ($matches[1] ?? $matches[2] ?? 0);

                return Http::response([
                    'tickets' => $tickets[$status] ?? [],
                    'total' => count($tickets[$status] ?? []),
                ]);
            }

            if (str_contains($request->url(), '/api/v2/agents')) {
                return Http::response(['agents' => [['id' => 10, 'first_name' => 'Aisha', 'last_name' => 'Khan']]]);
            }

            if (str_contains($request->url(), '/api/v2/groups')) {
                return Http::response(['groups' => [['id' => 20, 'name' => 'Service Desk']]]);
            }

            return Http::response([], 404);
        });

        $this->actingAs($user)
            ->getJson("/api/dashboards/freshservice?data_source_id={$source->id}")
            ->assertOk()
            ->assertJsonPath('data.summary.total', 5)
            ->assertJsonPath('data.summary.open', 1)
            ->assertJsonPath('data.summary.on_hold', 2)
            ->assertJsonPath('data.summary.overdue', 1)
            ->assertJsonPath('data.summary.due_today', 1)
            ->assertJsonPath('data.summary.unassigned', 1)
            ->assertJsonPath('data.summary.unresolved', 4)
            ->assertJsonPath('data.summary.sla_breached', 1)
            ->assertJsonPath('data.unresolved_by_priority.0.label', 'Urgent')
            ->assertJsonPath('data.unresolved_by_agent.0.label', 'Aisha Khan')
            ->assertJsonPath('data.sla_breached_by_group_agent.0.group', 'Service Desk')
            ->assertJsonPath('data.sla_breached_by_group_agent.0.agent', 'Aisha Khan')
            ->assertJsonPath('data.citation.source_name', 'Freshservice ITSM');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v2/tickets/filter')
            && str_contains($request->url(), 'workspace_id=0'));
        CarbonImmutable::setTestNow();
    }

    private function reportAndDashboard(User $user): array
    {
        $report = $this->makeReport($user);
        $dashboard = Dashboard::create([
            'name' => 'Executive Dashboard',
            'slug' => 'executive',
            'department' => $user->department,
            'visibility' => 'department',
            'is_active' => true,
        ]);
        $dashboard->reports()->attach($report, [
            'sort_order' => 0,
            'widget_size' => 'wide',
        ]);

        return [$report, $dashboard];
    }

    private function makeReport(User $user, string $visibility = 'enterprise'): Report
    {
        $report = Report::create([
            'user_id' => $user->id,
            'name' => 'Revenue Performance',
            'type' => 'sales',
            'description' => 'QA report',
            'visibility' => $visibility,
            'definition' => [
                'columns' => [
                    ['key' => 'period', 'label' => 'Period', 'type' => 'date'],
                    ['key' => 'region', 'label' => 'Region', 'type' => 'text'],
                    ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'currency'],
                ],
                'chart' => [
                    'type' => 'bar',
                    'category_key' => 'period',
                    'value_key' => 'revenue',
                ],
            ],
        ]);

        return $report->fresh();
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create([
            'name' => 'role-'.str()->random(8),
            'label' => 'Test role',
        ]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'group' => 'Test']
            );
            $role->permissions()->attach($permission);
        }

        $user = User::factory()->create([
            'department' => 'Finance',
            'is_active' => true,
        ]);
        $user->roles()->attach($role);

        return $user;
    }
}
