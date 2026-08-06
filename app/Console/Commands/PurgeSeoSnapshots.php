<?php

namespace App\Console\Commands;

use App\Services\Seo\SeoSnapshotService;
use Illuminate\Console\Command;

class PurgeSeoSnapshots extends Command
{
    protected $signature = 'seo:purge-snapshots {--days= : Override the configured retention period}';

    protected $description = 'Purge SEO snapshots that exceed the approved retention period';

    public function handle(SeoSnapshotService $snapshots): int
    {
        $days = (int) ($this->option('days') ?: config('seo.snapshot_retention_days'));

        if ($days < 1) {
            $this->error('The retention period must be at least one day.');

            return self::FAILURE;
        }

        $deleted = $snapshots->prune($days);

        $this->info("Purged {$deleted} expired SEO snapshot(s).");

        return self::SUCCESS;
    }
}
