<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\Report;
use App\Models\ReportScheduleRun;
use App\Services\Reporting\ReportDataService;
use App\Services\Reporting\ReportExportService;
use App\Services\Reporting\ScheduledReportDelivery;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateAndDeliverScheduledReport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $runId) {}

    public function uniqueId(): string
    {
        return "scheduled-report-run:{$this->runId}";
    }

    public function handle(
        ReportDataService $data,
        ReportExportService $exports,
        ScheduledReportDelivery $delivery
    ): void {
        $run = ReportScheduleRun::query()
            ->with(['schedule.creator', 'report', 'snapshot'])
            ->findOrFail($this->runId);

        if ($run->status === 'succeeded') {
            return;
        }

        if (! $run->schedule->creator?->is_active) {
            throw new \RuntimeException('The schedule owner is inactive.');
        }

        $reportIsExecutable = Report::query()
            ->visibleTo($run->schedule->creator)
            ->whereKey($run->report_id)
            ->exists();

        if (! $reportIsExecutable) {
            throw new \RuntimeException('The schedule owner is no longer authorized to run this report.');
        }

        $run->update([
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
            'error_message' => null,
        ]);

        $snapshot = $run->snapshot;

        if (! $snapshot) {
            $snapshot = $data->generate(
                $run->report,
                $run->schedule->creator,
                $run->schedule->filters ?? []
            );
            $run->update(['report_snapshot_id' => $snapshot->id]);
        }

        $rows = $data->filteredRows($snapshot, $run->schedule->filters ?? []);
        $contents = $run->schedule->format === 'xlsx'
            ? $exports->xlsx($run->report, $snapshot, $rows)
            : $exports->pdf($run->report, $snapshot, $rows);
        $channelResults = $delivery->deliver(
            $run->fresh(['schedule', 'report', 'snapshot']),
            $contents,
            count($rows),
        );

        $run->update([
            'status' => 'succeeded',
            'channel_results' => $channelResults,
            'finished_at' => now(),
        ]);
        $run->schedule->update([
            'last_run_at' => now(),
            'last_status' => 'succeeded',
            'failure_count' => 0,
            'last_error' => null,
        ]);

        AuditLog::create([
            'user_id' => $run->schedule->created_by,
            'event' => 'report.schedule.delivered',
            'auditable_type' => $run->schedule::class,
            'auditable_id' => (string) $run->schedule->id,
            'metadata' => [
                'run_id' => $run->id,
                'report_id' => $run->report_id,
                'snapshot_id' => $snapshot->id,
                'format' => $run->schedule->format,
                'channels' => array_keys($channelResults),
                'row_count' => count($rows),
            ],
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $run = ReportScheduleRun::query()->with('schedule')->find($this->runId);

        if (! $run || $run->status === 'succeeded') {
            return;
        }

        $message = str($exception?->getMessage() ?? 'Scheduled delivery failed.')->limit(1000)->toString();
        $run->update([
            'status' => 'failed',
            'error_message' => $message,
            'finished_at' => now(),
        ]);
        $run->schedule?->update([
            'last_run_at' => now(),
            'last_status' => 'failed',
            'failure_count' => ($run->schedule->failure_count ?? 0) + 1,
            'last_error' => $message,
        ]);
    }
}
