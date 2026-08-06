<?php

namespace Tests\Feature;

use App\Jobs\GenerateAndDeliverScheduledReport;
use App\Mail\ScheduledReportMail;
use App\Models\DataSource;
use App\Models\Permission;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\Role;
use App\Models\User;
use App\Services\Integrations\FreshserviceAnalyticsService;
use App\Services\Reporting\ReportDataService;
use App\Services\Reporting\ReportExportService;
use App\Services\Reporting\ScheduledReportDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScheduledReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyst_can_manage_and_queue_an_owned_schedule(): void
    {
        Queue::fake();
        $user = $this->analyst();
        $report = $this->report($user);
        $payload = $this->schedulePayload($report);

        $created = $this->actingAs($user)
            ->postJson('/api/schedules', $payload)
            ->assertCreated()
            ->assertJsonPath('data.report.id', $report->id)
            ->assertJsonPath('data.frequency', 'daily')
            ->assertJsonPath('data.recipients.0', 'finance@example.com');
        $scheduleId = $created->json('data.id');

        $this->actingAs($user)
            ->putJson("/api/schedules/{$scheduleId}", [
                ...$payload,
                'frequency' => 'weekly',
                'cron_expression' => '0 8 * * 1',
            ])
            ->assertOk()
            ->assertJsonPath('data.frequency', 'weekly');

        $this->actingAs($user)
            ->postJson("/api/schedules/{$scheduleId}/run")
            ->assertAccepted();
        Queue::assertPushed(GenerateAndDeliverScheduledReport::class);

        $rawRecipients = DB::table('report_schedules')->where('id', $scheduleId)->value('recipients');
        $this->assertStringNotContainsString('finance@example.com', $rawRecipients);
    }

    public function test_an_ungenerated_source_backed_report_is_available_for_scheduling(): void
    {
        $user = $this->analyst();
        $report = $this->report($user, $this->source($user));
        $report->update(['last_generated_at' => null]);

        $this->actingAs($user)
            ->getJson('/api/schedules')
            ->assertOk()
            ->assertJsonPath('reports.0.id', $report->id);

        $this->actingAs($user)
            ->postJson('/api/schedules', $this->schedulePayload($report))
            ->assertCreated()
            ->assertJsonPath('data.report.id', $report->id);
    }

    public function test_scheduled_job_generates_and_delivers_email_and_teams_report(): void
    {
        Mail::fake();
        Http::fake([
            'https://erp.example.com/api/reports*' => Http::response([
                'rows' => [['period' => '2026-07', 'revenue' => 425000]],
            ]),
            'https://teams.example.com/webhook' => Http::response('1', 200),
        ]);
        Config::set('services.teams.webhook_url', 'https://teams.example.com/webhook');
        $user = $this->analyst();
        $source = $this->source($user);
        $report = $this->report($user, $source);
        $schedule = $this->schedule($user, $report, ['email', 'teams']);
        $run = $schedule->runs()->create([
            'report_id' => $report->id,
            'triggered_by' => $user->id,
            'status' => 'queued',
            'trigger' => 'manual',
        ]);

        $job = new GenerateAndDeliverScheduledReport($run->id);
        $job->handle(
            app(ReportDataService::class),
            app(ReportExportService::class),
            app(ScheduledReportDelivery::class),
        );

        $run->refresh();
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('succeeded', $run->channel_results['email']['status']);
        $this->assertSame('succeeded', $run->channel_results['teams']['status']);
        $this->assertNotNull($run->report_snapshot_id);
        Mail::assertSent(ScheduledReportMail::class, fn ($mail) => $mail->hasTo('finance@example.com'));
        Http::assertSent(fn ($request) => $request->url() === 'https://teams.example.com/webhook'
            && data_get($request->data(), 'attachments.0.content.type') === 'AdaptiveCard');
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'report.schedule.delivered',
            'auditable_id' => (string) $schedule->id,
        ]);
    }

    public function test_freshservice_schedule_delivers_the_operational_email_summary(): void
    {
        Mail::fake();
        $user = $this->analyst();
        $source = DataSource::create([
            'name' => 'Freshservice ITSM',
            'type' => 'freshservice',
            'base_url' => 'https://company.freshservice.com',
            'status' => 'connected',
            'owner_id' => $user->id,
            'settings' => ['allowed_roles' => ['analyst']],
        ]);
        $source->apiConfiguration()->create([
            'auth_type' => 'basic',
            'credentials' => ['username' => 'fake-api-key', 'password' => 'X'],
            'timeout_seconds' => 30,
            'retry_count' => 1,
        ]);
        $report = Report::create([
            'user_id' => $user->id,
            'name' => 'Freshservice ITSM Summary',
            'type' => 'itsm_ticket_summary',
            'visibility' => 'private',
            'definition' => [
                'source_id' => $source->id,
                'columns' => [
                    ['key' => 'section', 'label' => 'Section', 'type' => 'text'],
                    ['key' => 'metric', 'label' => 'Metric', 'type' => 'text'],
                    ['key' => 'detail', 'label' => 'Detail', 'type' => 'text'],
                    ['key' => 'count', 'label' => 'Ticket Count', 'type' => 'number'],
                ],
            ],
        ]);
        $analytics = [
            'summary' => [
                'total' => 1000,
                'open' => 9,
                'on_hold' => 202,
                'overdue' => 250,
                'due_today' => 19,
                'unassigned' => 12,
                'unresolved' => 548,
                'sla_breached' => 218,
            ],
            'overall_ticket_summary' => [['label' => 'Assigned', 'value' => 297]],
            'unresolved_by_priority' => [['label' => 'Urgent', 'value' => 2]],
            'unresolved_by_status' => [['label' => 'Assigned', 'value' => 297]],
            'unresolved_by_agent' => [['label' => 'Aisha Khan', 'value' => 49]],
            'sla_breached_by_group_agent' => [[
                'group' => 'Service Desk',
                'agent' => 'Aisha Khan',
                'value' => 18,
            ]],
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'timezone' => 'Asia/Dubai',
                'unresolved_ticket_limit_reached' => false,
            ],
        ];
        $freshservice = \Mockery::mock(FreshserviceAnalyticsService::class);
        $freshservice->shouldReceive('analytics')
            ->once()
            ->with(\Mockery::type(DataSource::class), [])
            ->andReturn($analytics);
        $this->app->instance(FreshserviceAnalyticsService::class, $freshservice);
        $schedule = $this->schedule($user, $report);
        $run = $schedule->runs()->create([
            'report_id' => $report->id,
            'triggered_by' => $user->id,
            'status' => 'queued',
            'trigger' => 'manual',
        ]);

        (new GenerateAndDeliverScheduledReport($run->id))->handle(
            app(ReportDataService::class),
            app(ReportExportService::class),
            app(ScheduledReportDelivery::class),
        );

        Mail::assertSent(ScheduledReportMail::class, 1);
        $mail = Mail::sent(ScheduledReportMail::class)->first();
        $this->assertSame(548, data_get($mail->summary, 'itsm.summary.unresolved'));
        $this->assertStringContainsString('SLA Breached Tickets by Group &amp; Agent', $mail->render());
        $this->assertDatabaseHas('report_snapshots', [
            'report_id' => $report->id,
            'row_count' => 12,
        ]);
    }

    public function test_dispatch_command_queues_due_schedule_once_and_advances_it(): void
    {
        Queue::fake();
        $user = $this->analyst();
        $report = $this->report($user);
        $schedule = $this->schedule($user, $report);
        $schedule->update(['next_run_at' => now()->subMinute()]);

        $this->artisan('reports:dispatch-schedules')
            ->expectsOutput('Queued 1 scheduled report run(s).')
            ->assertSuccessful();

        Queue::assertPushed(GenerateAndDeliverScheduledReport::class, 1);
        $this->assertTrue($schedule->fresh()->next_run_at->isFuture());
        $this->assertDatabaseHas('report_schedule_runs', [
            'report_schedule_id' => $schedule->id,
            'status' => 'queued',
            'trigger' => 'scheduled',
        ]);
    }

    public function test_invalid_cron_and_missing_email_recipients_are_rejected(): void
    {
        $user = $this->analyst();
        $report = $this->report($user);

        $this->actingAs($user)
            ->postJson('/api/schedules', [
                ...$this->schedulePayload($report),
                'cron_expression' => 'not-a-cron',
                'recipients' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cron_expression', 'recipients']);
    }

    public function test_administrator_cannot_assign_a_schedule_to_a_report_its_owner_cannot_execute(): void
    {
        $owner = $this->analyst();
        $originalReport = $this->report($owner);
        $schedule = $this->schedule($owner, $originalReport);
        $otherOwner = User::factory()->create(['is_active' => true]);
        $inaccessibleReport = $this->report($otherOwner);
        $administrator = $this->administrator();

        $this->actingAs($administrator)
            ->putJson("/api/schedules/{$schedule->id}", $this->schedulePayload($inaccessibleReport))
            ->assertNotFound();

        $this->assertSame($originalReport->id, $schedule->fresh()->report_id);
    }

    public function test_the_test_suite_uses_an_isolated_in_memory_database(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }

    public function test_a_queued_job_rechecks_report_access_before_execution(): void
    {
        $owner = $this->analyst();
        $report = $this->report($owner);
        $schedule = $this->schedule($owner, $report);
        $run = $schedule->runs()->create([
            'report_id' => $report->id,
            'triggered_by' => $owner->id,
            'status' => 'queued',
            'trigger' => 'manual',
        ]);
        $report->update(['user_id' => User::factory()->create()->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The schedule owner is no longer authorized to run this report.');

        (new GenerateAndDeliverScheduledReport($run->id))->handle(
            app(ReportDataService::class),
            app(ReportExportService::class),
            app(ScheduledReportDelivery::class),
        );
    }

    private function schedulePayload(Report $report): array
    {
        return [
            'report_id' => $report->id,
            'frequency' => 'daily',
            'cron_expression' => '0 8 * * *',
            'timezone' => 'Asia/Dubai',
            'format' => 'pdf',
            'filters' => [],
            'delivery_channels' => ['email'],
            'recipients' => ['Finance@Example.com'],
            'is_active' => true,
        ];
    }

    private function analyst(): User
    {
        $role = Role::create(['name' => 'analyst', 'label' => 'Analyst']);

        foreach (['reports.view', 'reports.create', 'reports.schedule'] as $name) {
            $permission = Permission::create(['name' => $name, 'label' => $name, 'group' => 'Reports']);
            $role->permissions()->attach($permission);
        }

        $user = User::factory()->create(['department' => 'Finance', 'is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function administrator(): User
    {
        $role = Role::create(['name' => 'administrator', 'label' => 'Administrator']);
        $permission = Permission::firstOrCreate(
            ['name' => 'reports.schedule'],
            ['label' => 'Schedule reports', 'group' => 'Reports']
        );
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function source(User $user): DataSource
    {
        $source = DataSource::create([
            'name' => 'Scheduled ERP',
            'type' => 'erp',
            'base_url' => 'https://erp.example.com',
            'status' => 'connected',
            'owner_id' => $user->id,
            'settings' => ['data_path' => '/api/reports', 'allowed_roles' => ['analyst']],
        ]);
        $source->apiConfiguration()->create([
            'auth_type' => 'none',
            'timeout_seconds' => 30,
            'retry_count' => 1,
        ]);

        return $source;
    }

    private function report(User $user, ?DataSource $source = null): Report
    {
        $source ??= $this->source($user);

        $report = Report::create([
            'user_id' => $user->id,
            'name' => 'Scheduled financial overview',
            'type' => 'custom',
            'visibility' => 'private',
            'definition' => [
                'source_id' => $source?->id,
                'columns' => [
                    ['key' => 'period', 'label' => 'Period', 'type' => 'date'],
                    ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'currency'],
                ],
                'chart' => ['type' => 'bar', 'category_key' => 'period', 'value_key' => 'revenue'],
            ],
            'last_generated_at' => now(),
        ]);

        return $report;
    }

    private function schedule(User $user, Report $report, array $channels = ['email']): ReportSchedule
    {
        return ReportSchedule::create([
            'report_id' => $report->id,
            'created_by' => $user->id,
            'frequency' => 'daily',
            'cron_expression' => '0 8 * * *',
            'timezone' => 'Asia/Dubai',
            'format' => 'pdf',
            'filters' => [],
            'delivery_channels' => $channels,
            'recipients' => ['finance@example.com'],
            'is_active' => true,
            'next_run_at' => now()->addDay(),
        ]);
    }
}
