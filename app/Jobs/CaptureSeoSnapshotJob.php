<?php

namespace App\Jobs;

use App\Models\DataSource;
use App\Services\Seo\SearchConsoleGateway;
use App\Services\Seo\SeoSnapshotService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Captures a trailing-window Search Console snapshot for one property, storing
 * the query and page dimensions so ranking trends can be computed over time.
 *
 * Queued and unique per property/day so a re-dispatch (or scheduler overlap)
 * cannot double-capture; the snapshot service is itself idempotent as well.
 */
class CaptureSeoSnapshotJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $dataSourceId) {}

    public function uniqueId(): string
    {
        return "seo-snapshot:{$this->dataSourceId}:".now()->toDateString();
    }

    public function handle(SearchConsoleGateway $gateway, SeoSnapshotService $snapshots): void
    {
        $source = DataSource::query()
            ->where('type', 'google_search_console')
            ->where('status', 'connected')
            ->find($this->dataSourceId);

        if (! $source) {
            return;
        }

        $data = $gateway->pull($source);
        $window = $data['window'];
        $dimensions = (array) config('seo.snapshot_dimensions', ['query', 'page']);

        foreach ($dimensions as $dimension) {
            if (! isset($data[$dimension])) {
                continue;
            }

            $snapshots->capture($source, $dimension, $data[$dimension], $window['from'], $window['to']);
        }

        Log::info('Captured SEO snapshot.', [
            'data_source_id' => $source->id,
            'window' => $window,
            'dimensions' => $dimensions,
        ]);
    }
}
