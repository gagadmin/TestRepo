<?php

namespace App\Services\Seo;

use App\Models\DataSource;
use App\Services\Integrations\GoogleSearchConsoleService;
use Illuminate\Support\Carbon;

/**
 * SEO-facing access to Search Console.
 *
 * Wraps the existing GoogleSearchConsoleService (which queries one dimension per
 * call) and pulls the query, page and country dimensions for a window. This is
 * where Phase 4's multi-dimension / period-comparison support will be added; for
 * now it composes single-dimension calls the connector already supports.
 */
class SearchConsoleGateway
{
    public function __construct(private readonly GoogleSearchConsoleService $searchConsole) {}

    /**
     * Pull the three dimensions used by the Phase 1 analyzers.
     *
     * @return array{
     *   query: array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>},
     *   page: array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>},
     *   country: array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>},
     *   window: array{from: string, to: string}
     * }
     */
    public function pull(DataSource $source, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $siteUrl = data_get($source->settings, 'site_url');
        [$from, $to] = $this->window($dateFrom, $dateTo);

        $base = ['date_from' => $from, 'date_to' => $to, 'limit' => 200];

        return [
            'query' => $this->searchConsole->analytics([...$base, 'dimension' => 'query'], $siteUrl),
            'page' => $this->searchConsole->analytics([...$base, 'dimension' => 'page'], $siteUrl),
            'country' => $this->searchConsole->analytics([...$base, 'dimension' => 'country'], $siteUrl),
            'window' => ['from' => $from, 'to' => $to],
        ];
    }

    /**
     * Resolve the reporting window. Defaults to the configured window ending on
     * GSC's freshest complete day (it finalises data 2–3 days late, so a 3-day
     * lag avoids reporting an artificial drop).
     *
     * @return array{0: string, 1: string}
     */
    private function window(?string $dateFrom, ?string $dateTo): array
    {
        $days = max(1, (int) config('seo.window_days', 28));
        $end = $dateTo && $this->isDate($dateTo)
            ? Carbon::parse($dateTo)
            : Carbon::now('America/Los_Angeles')->subDays(3);
        $start = $dateFrom && $this->isDate($dateFrom)
            ? Carbon::parse($dateFrom)
            : $end->copy()->subDays($days);

        return [$start->toDateString(), $end->toDateString()];
    }

    private function isDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }
}
