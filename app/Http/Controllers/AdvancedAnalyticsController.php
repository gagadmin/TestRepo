<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsInsight;
use App\Models\AuditLog;
use App\Models\Report;
use App\Services\Analytics\AdvancedAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdvancedAnalyticsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $reportIds = Report::query()->visibleTo($request->user())->select('id');
        $insights = AnalyticsInsight::query()
            ->whereIn('report_id', $reportIds)
            ->with('report:id,name,type')
            ->latest('generated_at')
            ->limit(100)
            ->get();

        return response()->json([
            'summary' => [
                'total' => $insights->count(),
                'anomalies' => $insights->where('type', 'anomaly')->count(),
                'forecasts' => $insights->where('type', 'forecast')->count(),
                'actions' => $insights->where('type', 'recommendation')->count(),
            ],
            'data' => $insights->map(fn (AnalyticsInsight $insight) => $this->serialize($insight)),
            'reports' => Report::query()
                ->visibleTo($request->user())
                ->whereHas('snapshots')
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'last_generated_at']),
        ]);
    }

    public function generate(Request $request, int $report, AdvancedAnalyticsService $analytics): JsonResponse
    {
        $model = Report::query()
            ->visibleTo($request->user())
            ->with('latestSnapshot')
            ->findOrFail($report);
        abort_unless($model->latestSnapshot, 422, 'Generate a report snapshot before running advanced analytics.');

        try {
            $insights = $analytics->analyze($model, $model->latestSnapshot, $request->user());
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'event' => 'analytics.generated',
            'auditable_type' => Report::class,
            'auditable_id' => (string) $model->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                'snapshot_id' => $model->latestSnapshot->id,
                'batch_id' => $insights->first()?->batch_id,
                'insight_count' => $insights->count(),
            ],
        ]);

        return response()->json([
            'message' => 'Advanced analytics generated.',
            'data' => $insights->map(fn (AnalyticsInsight $insight) => $this->serialize($insight->load('report'))),
        ]);
    }

    private function serialize(AnalyticsInsight $insight): array
    {
        return [
            'id' => $insight->id,
            'batch_id' => $insight->batch_id,
            'report' => $insight->report?->only(['id', 'name', 'type']),
            'type' => $insight->type,
            'severity' => $insight->severity,
            'metric_key' => $insight->metric_key,
            'title' => $insight->title,
            'narrative' => $insight->narrative,
            'payload' => $insight->payload ?? [],
            'generated_at' => $insight->generated_at,
        ];
    }
}
