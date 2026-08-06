<?php

namespace App\Console\Commands;

use App\Models\Report;
use App\Models\ReportSnapshot;
use Illuminate\Console\Command;

class PurgeExpiredReportSnapshots extends Command
{
    protected $signature = 'reports:purge-snapshots {--days= : Override the configured retention period}';

    protected $description = 'Purge report snapshots that exceed the approved retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('reporting.snapshot_retention_days'));

        if ($days < 1) {
            $this->error('The retention period must be at least one day.');

            return self::FAILURE;
        }

        $reportIds = ReportSnapshot::query()
            ->where('generated_at', '<', now()->subDays($days))
            ->distinct()
            ->pluck('report_id');
        $deleted = ReportSnapshot::query()
            ->where('generated_at', '<', now()->subDays($days))
            ->delete();

        Report::query()
            ->whereIn('id', $reportIds)
            ->whereDoesntHave('snapshots')
            ->update(['last_generated_at' => null]);

        $this->info("Purged {$deleted} expired report snapshot(s).");

        return self::SUCCESS;
    }
}
