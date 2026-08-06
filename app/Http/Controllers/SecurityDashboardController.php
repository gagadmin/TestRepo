<?php

namespace App\Http\Controllers;

use App\Http\Requests\SecurityEventUpdateRequest;
use App\Models\AuditLog;
use App\Models\SecurityEvent;
use App\Services\Security\SecurityMonitor;
use App\Services\Security\SecurityPostureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SecurityDashboardController extends Controller
{
    public function __construct(private readonly SecurityPostureService $posture) {}

    /**
     * Full security posture payload.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trend_days' => ['nullable', 'integer', Rule::in([7, 30, 90])],
        ]);

        return response()->json([
            'data' => $this->posture->dashboard((int) ($validated['trend_days'] ?? 30)),
        ]);
    }

    /**
     * Paginated, filterable event list for investigation.
     */
    public function events(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'acknowledged', 'resolved', 'false_positive'])],
            'severity' => ['nullable', Rule::in(SecurityEvent::SEVERITIES)],
            'category' => ['nullable', 'string', 'max:48'],
            'detector' => ['nullable', 'string', 'max:64'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $events = SecurityEvent::query()
            ->with(['user:id,name,department', 'acknowledger:id,name', 'resolver:id,name'])
            ->when($validated['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($validated['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when($validated['category'] ?? null, fn ($q, $v) => $q->where('category', $v))
            ->when($validated['detector'] ?? null, fn ($q, $v) => $q->where('detector', $v))
            ->when($validated['date_from'] ?? null, fn ($q, $v) => $q->whereDate('first_detected_at', '>=', $v))
            ->when($validated['date_to'] ?? null, fn ($q, $v) => $q->whereDate('first_detected_at', '<=', $v))
            ->orderByRaw(
                "CASE status WHEN 'open' THEN 0 WHEN 'acknowledged' THEN 1 "
                ."WHEN 'resolved' THEN 2 ELSE 3 END"
            )
            ->orderByRaw(SecurityMonitor::severityOrder())
            ->orderByDesc('last_detected_at')
            ->paginate(50)
            ->withQueryString();

        return response()->json([
            'data' => collect($events->items())->map(fn (SecurityEvent $event) => [
                ...$this->posture->serialiseEvent($event),
                'acknowledged_by' => $event->acknowledger?->name,
                'resolved_by' => $event->resolver?->name,
                'resolution_note' => $event->resolution_note,
            ])->values(),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'total' => $events->total(),
            ],
            'filters' => [
                'detectors' => SecurityEvent::query()->distinct()->orderBy('detector')->pluck('detector'),
                'categories' => SecurityEvent::query()->distinct()->orderBy('category')->pluck('category'),
                'severities' => SecurityEvent::SEVERITIES,
                'statuses' => SecurityEvent::STATUSES,
            ],
        ]);
    }

    /**
     * Move a finding through its lifecycle. Requires security.manage.
     */
    public function updateEvent(SecurityEventUpdateRequest $request, int $event): JsonResponse
    {
        $model = SecurityEvent::findOrFail($event);
        $previous = $model->status;
        $status = $request->validated('status');
        $note = $request->validated('resolution_note');

        $attributes = ['status' => $status];

        if ($status === 'acknowledged' && ! $model->acknowledged_at) {
            $attributes['acknowledged_at'] = now();
            $attributes['acknowledged_by'] = $request->user()->id;
        }

        if (in_array($status, ['resolved', 'false_positive'], true)) {
            $attributes['resolved_at'] = now();
            $attributes['resolved_by'] = $request->user()->id;
            $attributes['resolution_note'] = $note;

            // Resolving without acknowledging still records who saw it first.
            if (! $model->acknowledged_at) {
                $attributes['acknowledged_at'] = now();
                $attributes['acknowledged_by'] = $request->user()->id;
            }
        }

        if ($status === 'open') {
            $attributes['resolved_at'] = null;
            $attributes['resolved_by'] = null;
        }

        $model->update($attributes);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'event' => 'security.event.status_changed',
            'auditable_type' => $model::class,
            'auditable_id' => (string) $model->id,
            'ip_address' => $request->ip(),
            'user_agent' => str($request->userAgent() ?? '')->limit(500)->toString(),
            'metadata' => [
                'from' => $previous,
                'to' => $status,
                'detector' => $model->detector,
                'severity' => $model->severity,
                'has_note' => filled($note),
            ],
        ]);

        return response()->json([
            'message' => 'Security event updated.',
            'data' => $this->posture->serialiseEvent($model->fresh()),
        ]);
    }

    /**
     * Trigger an on-demand scan. Rate limited at the route.
     */
    public function scan(Request $request, SecurityMonitor $monitor): JsonResponse
    {
        abort_unless($request->user()->hasPermission('security.manage'), 403);

        $scan = $monitor->scan('manual', $request->user()->id);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'event' => 'security.scan.triggered',
            'auditable_type' => $scan::class,
            'auditable_id' => (string) $scan->id,
            'ip_address' => $request->ip(),
            'user_agent' => str($request->userAgent() ?? '')->limit(500)->toString(),
            'metadata' => [
                'events_detected' => $scan->events_detected,
                'events_created' => $scan->events_created,
                'status' => $scan->status,
            ],
        ]);

        return response()->json([
            'message' => "Scan complete. {$scan->events_created} new finding(s) recorded.",
            'data' => $this->posture->lastScan(),
        ]);
    }
}
