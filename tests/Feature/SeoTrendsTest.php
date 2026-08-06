<?php

namespace Tests\Feature;

use App\Jobs\CaptureSeoSnapshotJob;
use App\Models\DataSource;
use App\Models\SeoSnapshot;
use App\Models\SeoSnapshotRow;
use App\Models\User;
use App\Services\Seo\Analyzers\RankingTrendAnalyzer;
use App\Services\Seo\SeoSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SeoTrendsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('seo.decline_threshold', 1.5);
        Config::set('seo.comparison_gap_days', 28);
        Config::set('seo.min_impressions', 50);
    }

    public function test_snapshot_capture_is_idempotent(): void
    {
        $source = $this->gscSource();
        $service = app(SeoSnapshotService::class);

        $payload = fn (int $count) => [
            'rows' => collect(range(1, $count))->map(fn ($i) => [
                'query' => "kw {$i}", 'clicks' => 1, 'impressions' => 100, 'ctr' => 1.0, 'position' => 9.0,
            ])->all(),
            'summary' => ['position' => 9.0, 'clicks' => 1, 'impressions' => 100],
        ];

        $service->capture($source, 'query', $payload(5), '2026-07-01', '2026-07-28');
        $service->capture($source, 'query', $payload(3), '2026-07-01', '2026-07-28');

        // One snapshot for the day/dimension; rows reflect the latest capture.
        $this->assertSame(1, SeoSnapshot::where('data_source_id', $source->id)->count());
        $this->assertSame(3, SeoSnapshotRow::count());
    }

    public function test_trend_analyzer_flags_declines_and_gains(): void
    {
        $source = $this->gscSource();

        $this->snapshot($source, now()->subDays(28)->toDateString(), [
            ['key' => 'declining kw', 'position' => 5.0, 'impressions' => 500],
            ['key' => 'gaining kw', 'position' => 12.0, 'impressions' => 400],
            ['key' => 'stable kw', 'position' => 8.0, 'impressions' => 300],
        ]);
        $this->snapshot($source, now()->toDateString(), [
            ['key' => 'declining kw', 'position' => 9.0, 'impressions' => 480],
            ['key' => 'gaining kw', 'position' => 7.0, 'impressions' => 450],
            ['key' => 'stable kw', 'position' => 8.2, 'impressions' => 300],
        ]);

        $result = app(RankingTrendAnalyzer::class)->analyze($source, 'query');

        $this->assertTrue($result['available']);
        $this->assertSame('declining kw', $result['declining'][0]['keyword']);
        $this->assertSame('gaining kw', $result['gaining'][0]['keyword']);
        $this->assertNotContains('stable kw', collect($result['declining'])->pluck('keyword'));
        // Trend map improves the gainer (positive) and penalises the decliner.
        $this->assertGreaterThan(0, $result['trend_map']['gaining kw']);
        $this->assertLessThan(0, $result['trend_map']['declining kw']);
    }

    public function test_trend_is_collecting_with_a_single_snapshot(): void
    {
        $source = $this->gscSource();
        $this->snapshot($source, now()->toDateString(), [
            ['key' => 'kw', 'position' => 9.0, 'impressions' => 500],
        ]);

        $result = app(RankingTrendAnalyzer::class)->analyze($source, 'query');

        $this->assertTrue($result['available']);
        $this->assertSame([], $result['declining']);
        $this->assertNull($result['baseline_on']);
    }

    public function test_capture_command_dispatches_a_job_per_connected_property(): void
    {
        Queue::fake();
        $this->gscSource();
        $this->gscSource('Second property');

        $this->artisan('seo:capture-snapshots')->assertSuccessful();

        Queue::assertPushed(CaptureSeoSnapshotJob::class, 2);
    }

    public function test_purge_removes_snapshots_beyond_retention(): void
    {
        $source = $this->gscSource();
        $this->snapshot($source, now()->subDays(400)->toDateString(), []);
        $this->snapshot($source, now()->toDateString(), []);

        $this->artisan('seo:purge-snapshots', ['--days' => 180])->assertSuccessful();

        $this->assertSame(1, SeoSnapshot::count());
    }

    /* ---- helpers ---- */

    private function gscSource(string $name = 'Aboudcar'): DataSource
    {
        return DataSource::create([
            'name' => $name,
            'type' => 'google_search_console',
            'status' => 'connected',
            'owner_id' => User::factory()->create()->id,
            'settings' => ['site_url' => 'https://gaholding.com'],
        ]);
    }

    private function snapshot(DataSource $source, string $capturedOn, array $rows): SeoSnapshot
    {
        $snapshot = SeoSnapshot::create([
            'data_source_id' => $source->id,
            'site_url' => 'https://gaholding.com',
            'dimension' => 'query',
            'captured_on' => $capturedOn,
            'window_from' => $capturedOn,
            'window_to' => $capturedOn,
            'summary' => ['position' => 8.0, 'clicks' => 100, 'impressions' => 5000],
        ]);

        foreach ($rows as $row) {
            SeoSnapshotRow::create([
                'seo_snapshot_id' => $snapshot->id,
                'key' => $row['key'],
                'clicks' => $row['clicks'] ?? 1,
                'impressions' => $row['impressions'] ?? 100,
                'ctr' => $row['ctr'] ?? 1.0,
                'position' => $row['position'] ?? 9.0,
            ]);
        }

        return $snapshot;
    }
}
