<?php

namespace App\Http\Controllers;

use App\Http\Requests\FreshserviceDashboardRequest;
use App\Http\Requests\SearchConsoleDashboardRequest;
use App\Models\Dashboard;
use App\Models\DataSource;
use App\Models\Report;
use App\Services\Integrations\FreshserviceAnalyticsService;
use App\Services\Integrations\GoogleSearchConsoleService;
use App\Services\Reporting\ReportDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Dashboard::query()
                ->visibleTo($request->user())
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'department', 'description', 'visibility']),
            'search_console_sources' => DataSource::query()
                ->where('type', 'google_search_console')
                ->orderBy('name')
                ->get(['id', 'name', 'status', 'owner_id', 'settings'])
                ->filter(fn (DataSource $source) => $source->isAccessibleBy($request->user()))
                ->map(fn (DataSource $source) => [
                    'id' => $source->id,
                    'name' => $source->name,
                    'status' => $source->status,
                    'site_url' => data_get($source->settings, 'site_url'),
                ])
                ->values(),
            'freshservice_sources' => DataSource::query()
                ->where(function ($query) {
                    $query->where('type', 'freshservice')
                        ->orWhere(function ($legacy) {
                            $legacy->where('type', 'internal_application')
                                ->where('base_url', 'like', '%.freshservice.com%');
                        });
                })
                ->orderBy('name')
                ->get(['id', 'name', 'status', 'owner_id', 'settings', 'base_url', 'type'])
                ->filter(fn (DataSource $source) => $source->isAccessibleBy($request->user()))
                ->map(fn (DataSource $source) => [
                    'id' => $source->id,
                    'name' => $source->name,
                    'status' => $source->status,
                ])
                ->values(),
        ]);
    }

    public function freshservice(
        FreshserviceDashboardRequest $request,
        FreshserviceAnalyticsService $freshservice,
    ): JsonResponse {
        $source = DataSource::query()
            ->where(function ($query) {
                $query->where('type', 'freshservice')
                    ->orWhere(function ($legacy) {
                        $legacy->where('type', 'internal_application')
                            ->where('base_url', 'like', '%.freshservice.com%');
                    });
            })
            ->findOrFail($request->integer('data_source_id'));

        abort_unless($source->isAccessibleBy($request->user()), 403);
        abort_unless($source->status === 'connected', 409, 'Test this Freshservice source successfully before loading dashboard data.');

        try {
            $result = $freshservice->analytics(
                $source,
                $request->safe()->except('data_source_id'),
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        return response()->json([
            'data' => [
                ...$result,
                'citation' => [
                    'source_id' => $source->id,
                    'source_name' => $source->name,
                    'source_type' => 'freshservice',
                    'retrieved_at' => now()->toIso8601String(),
                ],
            ],
        ]);
    }

    public function searchConsole(
        SearchConsoleDashboardRequest $request,
        GoogleSearchConsoleService $searchConsole,
    ): JsonResponse {
        $source = DataSource::query()
            ->where('type', 'google_search_console')
            ->findOrFail($request->integer('data_source_id'));

        abort_unless($source->isAccessibleBy($request->user()), 403);
        abort_unless($source->status === 'connected', 409, 'Test this Search Console source successfully before loading dashboard data.');

        try {
            $result = $searchConsole->analytics(
                $request->safe()->except('data_source_id'),
                data_get($source->settings, 'site_url'),
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        return response()->json([
            'data' => [
                ...$result,
                'citation' => [
                    'source_id' => $source->id,
                    'source_name' => $source->name,
                    'source_type' => $source->type,
                    'retrieved_at' => now()->toIso8601String(),
                ],
            ],
        ]);
    }

    public function show(Request $request, string $slug, ReportDataService $data): JsonResponse
    {
        $dashboard = Dashboard::query()
            ->visibleTo($request->user())
            ->where('is_active', true)
            ->where('slug', $slug)
            ->with(['reports' => fn ($query) => $query
                ->visibleTo($request->user())
                ->with(['owner:id,name', 'latestSnapshot'])])
            ->firstOrFail();
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'department' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'data' => [
                'id' => $dashboard->id,
                'name' => $dashboard->name,
                'slug' => $dashboard->slug,
                'department' => $dashboard->department,
                'description' => $dashboard->description,
                'layout' => $dashboard->layout ?? [],
                'reports' => $dashboard->reports->map(function (Report $report) use ($data, $filters) {
                    $snapshot = $report->latestSnapshot;

                    return [
                        'id' => $report->id,
                        'name' => $report->name,
                        'type' => $report->type,
                        'definition' => $report->definition,
                        'last_generated_at' => $report->last_generated_at,
                        'rows' => $data->filteredRows(
                            $snapshot,
                            $filters,
                            config('reporting.dashboard_row_limit')
                        ),
                        'summary' => $snapshot?->summary ?? [],
                        'citations' => $snapshot?->citations ?? [],
                        'widget' => [
                            'size' => $report->pivot->widget_size,
                            'settings' => $report->pivot->settings ?? [],
                        ],
                    ];
                }),
            ],
        ]);
    }
}
