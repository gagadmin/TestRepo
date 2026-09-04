<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\ReportSnapshot;
use App\Models\User;
use App\Services\Reporting\ReportExportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Operational telemetry: queue wait and schedule lateness.
 *
 * Delivery and connector outcomes are already durable in `report_schedule_runs`
 * and `integration_runs`. These two measurements were the gap, and they are the
 * pair that answer the question KI-003 raises: is anything actually running?
 * A stopped worker or a stopped scheduler otherwise leaves no trace — the run
 * records simply arrive late, looking like ordinary successes.
 */
class OperationalTelemetryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{message: string, context: array<string, mixed>}>
     */
    private function captureLogs(callable $work): array
    {
        $captured = [];
        Log::listen(function ($message) use (&$captured) {
            $captured[] = ['message' => $message->message, 'context' => $message->context];
        });

        $work();

        return $captured;
    }

    private function lineFor(array $captured, string $message): ?array
    {
        foreach ($captured as $line) {
            if ($line['message'] === $message) {
                return $line;
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------
     | Queue wait
     |------------------------------------------------------------------ */

    public function test_a_started_job_reports_how_long_it_waited(): void
    {
        $queuedAt = microtime(true) - 2.5;

        $captured = $this->captureLogs(function () use ($queuedAt) {
            Event::dispatch(new JobProcessing('database', new FakeQueueJob([
                'correlation_id' => 'queue-wait-0001',
                'queued_at' => $queuedAt,
            ])));
        });

        $line = $this->lineFor($captured, 'Queued job started.');
        $this->assertNotNull($line, 'No queue wait line was written.');
        $this->assertGreaterThanOrEqual(2400, $line['context']['queue_wait_ms']);
        $this->assertLessThan(4000, $line['context']['queue_wait_ms']);
        $this->assertSame('default', $line['context']['queue']);
    }

    public function test_the_queue_wait_line_carries_the_job_correlation_id(): void
    {
        /*
         * Ordering matters: the correlation listener is registered before the
         * telemetry listener, so the context is set by the time this line is
         * written. Without that, the measurement could not be joined to the
         * request or scheduled run that queued the job.
         */
        $captured = $this->captureLogs(function () {
            Event::dispatch(new JobProcessing('database', new FakeQueueJob([
                'correlation_id' => 'queue-wait-0002',
                'queued_at' => microtime(true) - 1,
            ])));
        });

        $line = $this->lineFor($captured, 'Queued job started.');
        $this->assertSame('queue-wait-0002', $line['context']['correlation_id']);
    }

    public function test_a_job_queued_before_this_existed_is_not_measured(): void
    {
        // An older payload has no timestamp. It must not produce a nonsense
        // measurement, and it must not break the worker.
        $captured = $this->captureLogs(function () {
            Event::dispatch(new JobProcessing('database', new FakeQueueJob([
                'correlation_id' => 'queue-wait-0003',
            ])));
        });

        $this->assertNull($this->lineFor($captured, 'Queued job started.'));
    }

    public function test_a_real_dispatch_records_a_queue_timestamp(): void
    {
        Config::set('queue.default', 'database');

        CorrelationProbeJob::dispatch();

        $payload = json_decode(DB::table('jobs')->value('payload'), true);
        $this->assertIsNumeric($payload['queued_at']);
    }

    /* ------------------------------------------------------------------
     | Schedule lateness
     |------------------------------------------------------------------ */

    public function test_dispatching_reports_how_late_the_schedules_were(): void
    {
        Queue::fake();
        $this->schedule(dueAt: CarbonImmutable::now()->subMinutes(30));
        $this->schedule(dueAt: CarbonImmutable::now()->subMinutes(10));

        $captured = $this->captureLogs(function () {
            $this->artisan('reports:dispatch-schedules')->assertSuccessful();
        });

        $line = $this->lineFor($captured, 'Dispatched due report schedules.');
        $this->assertNotNull($line);
        $this->assertSame(2, $line['context']['queued']);
        // The worst case is what says whether the scheduler stalled.
        $this->assertGreaterThanOrEqual(1790, $line['context']['max_lateness_seconds']);
        $this->assertLessThan(1900, $line['context']['max_lateness_seconds']);
    }

    public function test_lateness_is_reported_as_a_median_not_an_average(): void
    {
        /*
         * One schedule inactive for a long time would drag an average far from
         * what the rest of the batch actually experienced, which is exactly the
         * case where the number is being read under pressure.
         */
        Queue::fake();
        $this->schedule(dueAt: CarbonImmutable::now()->subSeconds(10));
        $this->schedule(dueAt: CarbonImmutable::now()->subSeconds(20));
        $this->schedule(dueAt: CarbonImmutable::now()->subDays(30));

        $captured = $this->captureLogs(function () {
            $this->artisan('reports:dispatch-schedules')->assertSuccessful();
        });

        $line = $this->lineFor($captured, 'Dispatched due report schedules.');
        $this->assertLessThan(60, $line['context']['median_lateness_seconds']);
        $this->assertGreaterThan(2_000_000, $line['context']['max_lateness_seconds']);
    }

    public function test_nothing_is_reported_when_no_schedule_is_due(): void
    {
        Queue::fake();
        $this->schedule(dueAt: CarbonImmutable::now()->addHour());

        $captured = $this->captureLogs(function () {
            $this->artisan('reports:dispatch-schedules')->assertSuccessful();
        });

        $this->assertNull($this->lineFor($captured, 'Dispatched due report schedules.'));
    }

    /* ------------------------------------------------------------------
     | Export cost
     |------------------------------------------------------------------ */

    /**
     * @return list<array{0: string}>
     */
    public static function exportFormats(): array
    {
        return [['xlsx'], ['pdf']];
    }

    #[DataProvider('exportFormats')]
    public function test_an_export_reports_its_cost(string $format): void
    {
        [$report, $snapshot, $rows] = $this->exportFixture();

        $captured = $this->captureLogs(function () use ($format, $report, $snapshot, $rows) {
            app(ReportExportService::class)->{$format}($report, $snapshot, $rows);
        });

        $line = $this->lineFor($captured, 'Report export generated.');
        $this->assertNotNull($line, "No export cost line was written for {$format}.");
        $this->assertSame($format, $line['context']['format']);
        $this->assertSame($report->id, $line['context']['report_id']);
        $this->assertSame(3, $line['context']['rows']);
        $this->assertSame(2, $line['context']['columns']);
        $this->assertGreaterThan(0, $line['context']['bytes']);
        $this->assertGreaterThanOrEqual(0, $line['context']['duration_ms']);
    }

    public function test_an_export_never_logs_the_data_it_exported(): void
    {
        // The rows are business data. Only the shape of the export belongs in a
        // log line.
        [$report, $snapshot, $rows] = $this->exportFixture();

        $captured = $this->captureLogs(function () use ($report, $snapshot, $rows) {
            app(ReportExportService::class)->xlsx($report, $snapshot, $rows);
        });

        $line = $this->lineFor($captured, 'Report export generated.');
        $encoded = json_encode($line);
        $this->assertStringNotContainsString('Sensitive Supplier', $encoded);
        $this->assertStringNotContainsString('99999', $encoded);
    }

    /* ------------------------------------------------------------------
     | Helpers
     |------------------------------------------------------------------ */

    /**
     * @return array{0: Report, 1: ReportSnapshot, 2: list<array<string, mixed>>}
     */
    private function exportFixture(): array
    {
        $owner = User::factory()->create(['is_active' => true]);
        $report = Report::create([
            'user_id' => $owner->id,
            'name' => 'Supplier Spend',
            'type' => 'procurement_spend',
            'visibility' => 'private',
            'definition' => ['columns' => [
                ['key' => 'supplier', 'label' => 'Supplier', 'type' => 'text'],
                ['key' => 'spend', 'label' => 'Spend', 'type' => 'currency'],
            ]],
        ]);

        $rows = [
            ['supplier' => 'Sensitive Supplier A', 'spend' => 99999],
            ['supplier' => 'Sensitive Supplier B', 'spend' => 12345],
            ['supplier' => 'Sensitive Supplier C', 'spend' => 6789],
        ];

        $snapshot = $report->snapshots()->create([
            'generated_by' => $owner->id,
            'data' => $rows,
            'summary' => [],
            'citations' => [],
            'row_count' => count($rows),
            'generated_at' => now(),
        ]);

        return [$report, $snapshot, $rows];
    }

    private function schedule(CarbonImmutable $dueAt): ReportSchedule
    {
        $owner = User::factory()->create(['is_active' => true]);
        $source = DataSource::create([
            'name' => 'ERP '.uniqid('', true),
            'type' => 'erp',
            'base_url' => 'https://erp.example.test',
            'status' => 'connected',
            'owner_id' => $owner->id,
            'settings' => [],
        ]);

        $report = Report::create([
            'user_id' => $owner->id,
            'name' => 'Spend',
            'type' => 'procurement_spend',
            'visibility' => 'private',
            'definition' => ['source_id' => $source->id, 'columns' => [['key' => 'a', 'label' => 'A']]],
        ]);

        return ReportSchedule::create([
            'report_id' => $report->id,
            'created_by' => $owner->id,
            'frequency' => 'daily',
            'cron_expression' => '0 8 * * *',
            'timezone' => 'UTC',
            'format' => 'pdf',
            'filters' => [],
            'delivery_channels' => ['email'],
            'recipients' => ['ops@example.test'],
            'is_active' => true,
            'next_run_at' => $dueAt,
        ]);
    }
}
