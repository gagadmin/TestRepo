<?php

namespace App\Console\Commands;

use App\Jobs\GenerateAndDeliverScheduledReport;
use App\Models\ReportSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        $latenessSeconds = [];

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$queued, &$latenessSeconds) {
                $schedule = ReportSchedule::query()->lockForUpdate()->find($id);

                if (! $schedule?->is_active || ! $schedule->next_run_at?->lte(now())) {
                    return;
                }

                /*
                 * How late this run is against the time it was due. Captured
                 * before the update below, which replaces `next_run_at` with
                 * the following occurrence. A schedule dispatched promptly
                 * measures a few seconds; a large value means the scheduler
                 * stopped running and has just caught up, which is otherwise
                 * invisible - the run still records as a normal success.
                 */
                $lateness = max(0, $schedule->next_run_at->diffInSeconds(now()));
                $latenessSeconds[] = $lateness;

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

        if ($latenessSeconds !== []) {
            Log::info('Dispatched due report schedules.', [
                'queued' => $queued,
                'max_lateness_seconds' => max($latenessSeconds),
                'median_lateness_seconds' => $this->median($latenessSeconds),
            ]);
        }

        $this->info("Queued {$queued} scheduled report run(s).");

        return self::SUCCESS;
    }

    /**
     * Median rather than mean: one schedule that has been inactive for weeks
     * would drag an average far from what the rest of the batch experienced.
     *
     * @param  list<int>  $values
     */
    private function median(array $values): int
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }
}
