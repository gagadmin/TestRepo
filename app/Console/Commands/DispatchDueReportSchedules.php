<?php

namespace App\Console\Commands;

use App\Jobs\GenerateAndDeliverScheduledReport;
use App\Models\ReportSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchDueReportSchedules extends Command
{
    protected $signature = 'reports:dispatch-schedules';

    protected $description = 'Queue every active report schedule that is due';

    public function handle(): int
    {
        $ids = ReportSchedule::query()
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->limit(100)
            ->pluck('id');
        $queued = 0;

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$queued) {
                $schedule = ReportSchedule::query()->lockForUpdate()->find($id);

                if (! $schedule?->is_active || ! $schedule->next_run_at?->lte(now())) {
                    return;
                }

                $run = $schedule->runs()->create([
                    'report_id' => $schedule->report_id,
                    'triggered_by' => $schedule->created_by,
                    'status' => 'queued',
                    'trigger' => 'scheduled',
                ]);
                $schedule->update(['next_run_at' => $schedule->calculateNextRun(now())]);
                GenerateAndDeliverScheduledReport::dispatch($run->id)->afterCommit();
                $queued++;
            });
        }

        $this->info("Queued {$queued} scheduled report run(s).");

        return self::SUCCESS;
    }
}
