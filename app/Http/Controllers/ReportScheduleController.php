<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportScheduleRequest;
use App\Jobs\GenerateAndDeliverScheduledReport;
use App\Models\Report;
use App\Models\ReportSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportScheduleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $isAdmin = $this->isAdmin($request);
        $schedules = ReportSchedule::query()
            ->when(! $isAdmin, fn ($query) => $query->where('created_by', $request->user()->id))
            ->with([
                'report:id,name,type,visibility',
                'creator:id,name',
                'runs' => fn ($query) => $query->latest()->limit(5),
            ])
            ->latest()
            ->get()
            ->map(fn (ReportSchedule $schedule) => $this->serialize($schedule));

        return response()->json([
            'data' => $schedules,
            'reports' => Report::query()
                ->visibleTo($request->user())
                ->whereNotNull('definition->source_id')
                ->orderBy('name')
                ->get(['id', 'name', 'type']),
            'timezones' => collect(timezone_identifiers_list())
                ->filter(fn (string $timezone) => str_starts_with($timezone, 'Asia/')
                    || str_starts_with($timezone, 'Europe/')
                    || str_starts_with($timezone, 'America/')
                    || $timezone === 'UTC')
                ->values(),
            'teams_configured' => filled(config('services.teams.webhook_url')),
        ]);
    }

    public function store(ReportScheduleRequest $request): JsonResponse
    {
        $report = $this->executableReport($request, $request->integer('report_id'));
        $schedule = new ReportSchedule([
            ...$request->safe()->only([
                'frequency', 'cron_expression', 'timezone', 'format', 'filters',
                'delivery_channels', 'is_active',
            ]),
            'report_id' => $report->id,
            'created_by' => $request->user()->id,
            'recipients' => $this->normaliseRecipients($request->input('recipients', [])),
        ]);
        $schedule->next_run_at = $schedule->is_active ? $schedule->calculateNextRun(now()) : null;
        $schedule->save();

        return response()->json([
            'message' => 'Report schedule created.',
            'data' => $this->serialize($schedule->load(['report', 'creator', 'runs'])),
        ], 201);
    }

    public function update(ReportScheduleRequest $request, int $schedule): JsonResponse
    {
        $model = $this->editableSchedule($request, $schedule);
        $report = $this->executableReport($request, $request->integer('report_id'), $model);
        $model->fill([
            ...$request->safe()->only([
                'frequency', 'cron_expression', 'timezone', 'format', 'filters',
                'delivery_channels', 'is_active',
            ]),
            'report_id' => $report->id,
            'recipients' => $this->normaliseRecipients($request->input('recipients', [])),
        ]);
        $model->next_run_at = $model->is_active ? $model->calculateNextRun(now()) : null;
        $model->save();

        return response()->json([
            'message' => 'Report schedule updated.',
            'data' => $this->serialize($model->fresh(['report', 'creator', 'runs'])),
        ]);
    }

    public function destroy(Request $request, int $schedule): JsonResponse
    {
        $this->editableSchedule($request, $schedule)->delete();

        return response()->json(['message' => 'Report schedule removed.']);
    }

    public function runNow(Request $request, int $schedule): JsonResponse
    {
        $model = $this->editableSchedule($request, $schedule);
        $run = $model->runs()->create([
            'report_id' => $model->report_id,
            'triggered_by' => $request->user()->id,
            'status' => 'queued',
            'trigger' => 'manual',
        ]);
        GenerateAndDeliverScheduledReport::dispatch($run->id);

        return response()->json([
            'message' => 'Report delivery queued.',
            'run_id' => $run->id,
        ], 202);
    }

    private function editableSchedule(Request $request, int $schedule): ReportSchedule
    {
        $model = ReportSchedule::query()->findOrFail($schedule);
        abort_unless($model->created_by === $request->user()->id || $this->isAdmin($request), 403);

        return $model;
    }

    private function isAdmin(Request $request): bool
    {
        return $request->user()->roles()->where('name', 'administrator')->exists();
    }

    private function executableReport(Request $request, int $reportId, ?ReportSchedule $schedule = null): Report
    {
        $executor = $schedule?->creator ?? $request->user();

        return Report::query()
            ->visibleTo($executor)
            ->whereNotNull('definition->source_id')
            ->findOrFail($reportId);
    }

    private function normaliseRecipients(array $recipients): array
    {
        return collect($recipients)
            ->map(fn (string $email) => strtolower(trim($email)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function serialize(ReportSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'report' => $schedule->report?->only(['id', 'name', 'type', 'visibility']),
            'creator' => $schedule->creator?->only(['id', 'name']),
            'frequency' => $schedule->frequency,
            'cron_expression' => $schedule->cron_expression,
            'timezone' => $schedule->timezone,
            'format' => $schedule->format,
            'filters' => $schedule->filters ?? [],
            'delivery_channels' => $schedule->delivery_channels,
            'recipients' => $schedule->recipients,
            'is_active' => $schedule->is_active,
            'next_run_at' => $schedule->next_run_at,
            'last_run_at' => $schedule->last_run_at,
            'last_status' => $schedule->last_status,
            'failure_count' => $schedule->failure_count,
            'last_error' => $schedule->last_error,
            'runs' => $schedule->relationLoaded('runs')
                ? $schedule->runs->map(fn ($run) => [
                    'id' => $run->id,
                    'status' => $run->status,
                    'trigger' => $run->trigger,
                    'channel_results' => $run->channel_results ?? [],
                    'error_message' => $run->error_message,
                    'started_at' => $run->started_at,
                    'finished_at' => $run->finished_at,
                    'created_at' => $run->created_at,
                ])->values()
                : [],
        ];
    }
}
