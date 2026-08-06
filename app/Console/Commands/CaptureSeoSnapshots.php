<?php

namespace App\Console\Commands;

use App\Jobs\CaptureSeoSnapshotJob;
use App\Models\DataSource;
use Illuminate\Console\Command;

class CaptureSeoSnapshots extends Command
{
    protected $signature = 'seo:capture-snapshots
                            {--source-id= : Capture a single data source}
                            {--sync : Run inline instead of queueing}';

    protected $description = 'Capture nightly Search Console snapshots for connected properties (feeds SEO trend analysis)';

    public function handle(): int
    {
        $query = DataSource::query()
            ->where('type', 'google_search_console')
            ->where('status', 'connected');

        if ($sourceId = $this->option('source-id')) {
            $query->whereKey($sourceId);
        }

        $sources = $query->get();

        if ($sources->isEmpty()) {
            $this->info('No connected Search Console properties to capture.');

            return self::SUCCESS;
        }

        foreach ($sources as $source) {
            $job = new CaptureSeoSnapshotJob($source->id);

            if ($this->option('sync')) {
                dispatch_sync($job);
            } else {
                dispatch($job);
            }

            $this->line("  Queued snapshot capture: {$source->name}");
        }

        $this->info("Dispatched capture for {$sources->count()} property(ies).");

        return self::SUCCESS;
    }
}
