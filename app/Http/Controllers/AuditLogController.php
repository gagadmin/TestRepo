<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuditLogIndexRequest;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;

class AuditLogController extends Controller
{
    public function index(AuditLogIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $logs = AuditLog::query()
            ->with('user:id,name,email')
            ->when(filled($validated['event'] ?? null), fn ($query) => $query
                ->where('event', 'like', '%'.trim($validated['event']).'%'))
            ->when(filled($validated['date_from'] ?? null), fn ($query) => $query
                ->where('created_at', '>=', $validated['date_from'].' 00:00:00'))
            ->when(filled($validated['date_to'] ?? null), fn ($query) => $query
                ->where('created_at', '<=', $validated['date_to'].' 23:59:59'))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return response()->json([
            'data' => $logs->getCollection()->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'actor' => $log->user?->only(['id', 'name', 'email']),
                'auditable_type' => $log->auditable_type,
                'auditable_id' => $log->auditable_id,
                'ip_address' => $log->ip_address,
                'metadata' => $log->metadata ?? [],
                'created_at' => $log->created_at,
            ]),
            'event_types' => AuditLog::query()
                ->select('event')
                ->distinct()
                ->orderBy('event')
                ->limit(100)
                ->pluck('event'),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
