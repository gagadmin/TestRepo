<?php

namespace App\Http\Controllers;

use App\Http\Requests\AiToolRequest;
use App\Models\AiCorrection;
use App\Models\AiToolDefinition;
use App\Models\AiToolFailure;
use App\Models\AuditLog;
use App\Models\DataSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Administration of what the AI assistant can reach, plus review of the
 * corrections that shape its answers.
 *
 * Gated on `integrations.manage`. Every change is audited, because widening the
 * assistant's reach decides what data any `ai.chat` user can surface.
 */
class AiToolController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tools = AiToolDefinition::query()
            ->with('updatedBy:id,name')
            ->ordered()
            ->get();

        // Connected sources per type, so the UI can flag a tool that is enabled
        // but has nothing to talk to — the condition behind "no ITSM connector".
        $connectedByType = DataSource::query()
            ->where('status', 'connected')
            ->get(['id', 'name', 'type'])
            ->groupBy('type');

        return response()->json([
            'data' => $tools->map(fn (AiToolDefinition $tool) => $this->serialize($tool, $connectedByType)),
            'handlers' => collect(AiToolDefinition::HANDLERS)
                ->map(fn (array $handler, string $key) => ['value' => $key, ...$handler])
                ->values(),
            'source_types' => collect(config('integrations.types', []))
                ->map(fn (array $type, string $key) => [
                    'value' => $key,
                    'label' => $type['label'] ?? $key,
                    'connected_count' => $connectedByType->get($key)?->count() ?? 0,
                ])
                ->sortBy('label')
                ->values(),

            // Connected sources no enabled tool covers.
            'uncovered_sources' => $this->uncoveredSources($tools, $connectedByType),

            'meta' => [
                'enabled_count' => $tools->where('is_enabled', true)->count(),
                'total_count' => $tools->count(),
                'cache_seconds' => (int) config('ai.tool_cache_seconds'),
            ],
        ]);
    }

    public function store(AiToolRequest $request): JsonResponse
    {
        $data = $request->validated();
        // The API key is never mass-assigned: it is written to the encrypted
        // secret_options, never into the plaintext tool row.
        $apiKey = $data['api_key'] ?? null;
        unset($data['api_key']);

        $tool = AiToolDefinition::create([
            ...$data,
            'updated_by' => $request->user()->id,
        ]);

        if (filled($apiKey)) {
            $tool->secret_options = ['api_key' => $apiKey];
            $tool->save();
        }

        $this->audit($request, 'ai.tool.created', $tool);

        return response()->json([
            'message' => "The {$tool->label} tool has been created.",
            'data' => $this->serialize($tool),
        ], 201);
    }

    public function update(AiToolRequest $request, int $tool): JsonResponse
    {
        $model = AiToolDefinition::findOrFail($tool);
        $before = $model->only(['name', 'handler', 'source_types', 'is_enabled']);

        $data = $request->validated();
        // A blank key was stripped in the request, so its presence here means an
        // intentional change; absence means keep the stored key untouched.
        $apiKey = $data['api_key'] ?? null;
        unset($data['api_key']);

        $model->update([
            ...$data,
            'updated_by' => $request->user()->id,
        ]);

        if (filled($apiKey)) {
            $model->secret_options = ['api_key' => $apiKey];
            $model->save();
        }

        $this->audit($request, 'ai.tool.updated', $model, [
            'before' => $before,
            'after' => $model->only(['name', 'handler', 'source_types', 'is_enabled']),
            // Record that the key was rotated — but never the key itself.
            'api_key_changed' => filled($apiKey),
        ]);

        return response()->json([
            'message' => "The {$model->label} tool has been updated.",
            'data' => $this->serialize($model->fresh('updatedBy')),
        ]);
    }

    /**
     * Enable or disable without opening the editor.
     */
    public function toggle(Request $request, int $tool): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.manage'), 403);

        $validated = $request->validate(['is_enabled' => ['required', 'boolean']]);
        $model = AiToolDefinition::findOrFail($tool);

        $model->update([
            'is_enabled' => $validated['is_enabled'],
            'updated_by' => $request->user()->id,
        ]);

        $this->audit($request, 'ai.tool.toggled', $model, [
            'is_enabled' => $model->is_enabled,
        ]);

        return response()->json([
            'message' => $model->is_enabled
                ? "{$model->label} is now available to the assistant."
                : "{$model->label} is no longer available to the assistant.",
            'data' => $this->serialize($model),
        ]);
    }

    public function destroy(Request $request, int $tool): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.manage'), 403);

        $model = AiToolDefinition::findOrFail($tool);
        $label = $model->label;

        $this->audit($request, 'ai.tool.deleted', $model, [
            'name' => $model->name,
            'source_types' => $model->source_types,
        ]);

        $model->delete();

        return response()->json(['message' => "The {$label} tool has been removed."]);
    }

    /* ------------------------------------------------------------------
     * Connector failures
     * ------------------------------------------------------------------ */

    public function failures(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.manage'), 403);

        return response()->json([
            'data' => AiToolFailure::query()
                ->with('dataSource:id,name,type')
                ->unresolved()
                ->orderByDesc('last_failed_at')
                ->limit(50)
                ->get()
                ->map(fn (AiToolFailure $failure) => [
                    'id' => $failure->id,
                    'tool_name' => $failure->tool_name,
                    'source' => $failure->dataSource?->name,
                    'reason' => $failure->reason,
                    'message' => $failure->message,
                    'occurrences' => $failure->occurrences,
                    'first_failed_at' => $failure->first_failed_at?->toIso8601String(),
                    'last_failed_at' => $failure->last_failed_at?->toIso8601String(),
                ]),
        ]);
    }

    public function resolveFailure(Request $request, int $failure): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.manage'), 403);

        $model = AiToolFailure::findOrFail($failure);
        $model->update(['resolved' => true]);

        // Deliberately not deleted: a recurrence reopens the same row, so the
        // history of how often a connector breaks is preserved.
        return response()->json(['message' => 'Marked as resolved. It will reopen if it recurs.']);
    }

    /* ------------------------------------------------------------------
     * Correction review
     * ------------------------------------------------------------------ */

    public function corrections(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.manage'), 403);

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(AiCorrection::STATUSES)],
        ]);

        $corrections = AiCorrection::query()
            ->with(['reporter:id,name', 'reviewer:id,name'])
            ->when(
                $validated['status'] ?? null,
                fn ($query, $status) => $query->where('status', $status),
                fn ($query) => $query->where('status', 'pending'),
            )
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $corrections->map(fn (AiCorrection $correction) => [
                'id' => $correction->id,
                'question' => $correction->question,
                'incorrect_answer' => $correction->incorrect_answer,
                'correction' => $correction->correction,
                'topic' => $correction->topic,
                'applies_to_tools' => $correction->applies_to_tools,
                'status' => $correction->status,
                'reported_by' => $correction->reporter?->name,
                'reviewed_by' => $correction->reviewer?->name,
                'reviewed_at' => $correction->reviewed_at?->toIso8601String(),
                'applied_count' => $correction->applied_count,
                'created_at' => $correction->created_at?->toIso8601String(),
            ]),
            'pending_count' => AiCorrection::pending()->count(),
        ]);
    }

    /**
     * Approve or reject a correction.
     *
     * Approval is what makes the text reach the model, so it is the security
     * decision in this feature: approved text becomes trusted guidance on every
     * later question, for every user.
     */
    public function reviewCorrection(Request $request, int $correction): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.manage'), 403);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            // Editable on approval so a reviewer can tighten the wording before
            // it starts influencing answers.
            'correction' => ['nullable', 'string', 'max:2000'],
            'topic' => ['nullable', 'string', 'max:120'],
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $model = AiCorrection::findOrFail($correction);

        $model->update([
            'status' => $validated['status'],
            'correction' => $validated['correction'] ?? $model->correction,
            'topic' => $validated['topic'] ?? $model->topic,
            'review_note' => $validated['review_note'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'event' => 'ai.correction.reviewed',
            'auditable_type' => AiCorrection::class,
            'auditable_id' => (string) $model->id,
            'ip_address' => $request->ip(),
            'user_agent' => str($request->userAgent() ?? '')->limit(500)->toString(),
            'metadata' => [
                'status' => $model->status,
                'topic' => $model->topic,
                // The correction text itself is not audited: it is encrypted at
                // rest and may contain business data.
                'edited_before_approval' => filled($validated['correction'] ?? null),
            ],
        ]);

        return response()->json([
            'message' => $model->status === 'approved'
                ? 'Approved. The assistant will apply this guidance from now on.'
                : 'Rejected. It will not influence any answers.',
        ]);
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    private function serialize(AiToolDefinition $tool, $connectedByType = null): array
    {
        $connected = $connectedByType
            ? collect($tool->source_types ?? [])
                ->flatMap(fn (string $type) => $connectedByType->get($type) ?? collect())
                ->values()
            : null;

        return [
            'id' => $tool->id,
            'name' => $tool->name,
            'label' => $tool->label,
            'description' => $tool->description,
            'handler' => $tool->handler,
            'handler_label' => $tool->handlerLabel(),
            'handler_valid' => $tool->hasValidHandler(),
            'is_standalone' => $tool->isStandalone(),
            'uses_ai_provider' => $tool->usesAiProvider(),
            'source_types' => $tool->source_types,
            'is_enabled' => $tool->is_enabled,
            'sort_order' => $tool->sort_order,
            'updated_by' => $tool->updatedBy?->name,
            'updated_at' => $tool->updated_at?->toIso8601String(),

            // Non-secret provider configuration for standalone tools. The API
            // key is NEVER returned — only a flag saying whether one is stored,
            // mirroring how ApiConfiguration exposes has_credentials.
            'provider' => $tool->isStandalone() ? $this->providerConfig($tool) : null,
            'provider_configured' => $tool->isStandalone() ? $tool->providerConfigured() : null,
            'has_api_key' => $tool->isStandalone()
                ? filled(($tool->secret_options ?? [])['api_key'] ?? null)
                : null,

            // Reachability, so an enabled-but-useless tool is visible at a
            // glance. Standalone tools have no data sources, so this stays null
            // and the UI uses provider_configured instead.
            'connected_sources' => $tool->isStandalone()
                ? null
                : $connected?->map(fn ($source) => [
                    'id' => $source->id,
                    'name' => $source->name,
                ])->all(),
            'connected_source_count' => $tool->isStandalone()
                ? null
                : ($connected?->count() ?? $tool->reachableSourceCount()),
        ];
    }

    /**
     * The non-secret provider settings for a standalone tool.
     *
     * @return array<string, mixed>
     */
    private function providerConfig(AiToolDefinition $tool): array
    {
        $options = $tool->options ?? [];

        // Handlers that reuse an application AI provider carry only behavioural
        // options — never an endpoint, host list, or key.
        if ($tool->usesAiProvider()) {
            return [
                'model' => $options['model'] ?? null,
                'max_output_tokens' => $options['max_output_tokens'] ?? 1500,
                'tool_type' => $options['tool_type'] ?? 'web_search',
            ];
        }

        return [
            'endpoint' => $options['endpoint'] ?? null,
            'allowed_hosts' => $options['allowed_hosts'] ?? [],
            'auth_scheme' => $options['auth_scheme'] ?? 'bearer',
            'key_header' => $options['key_header'] ?? 'X-API-Key',
            'max_results' => $options['max_results'] ?? 5,
            'timeout_seconds' => $options['timeout_seconds'] ?? 15,
            'cache_seconds' => $options['cache_seconds'] ?? 300,
        ];
    }

    /**
     * Connected sources that no enabled tool can read.
     */
    private function uncoveredSources($tools, $connectedByType): array
    {
        $covered = $tools
            ->where('is_enabled', true)
            ->filter(fn (AiToolDefinition $tool) => $tool->hasValidHandler())
            ->flatMap(fn (AiToolDefinition $tool) => $tool->source_types ?? [])
            ->unique();

        return $connectedByType
            ->reject(fn ($sources, string $type) => $covered->contains($type))
            ->flatMap(fn ($sources, string $type) => $sources->map(fn ($source) => [
                'id' => $source->id,
                'name' => $source->name,
                'type' => $type,
                'type_label' => config("integrations.types.{$type}.label", $type),
            ]))
            ->values()
            ->all();
    }

    private function audit(Request $request, string $event, AiToolDefinition $tool, array $metadata = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'event' => $event,
            'auditable_type' => AiToolDefinition::class,
            'auditable_id' => (string) $tool->id,
            'ip_address' => $request->ip(),
            'user_agent' => str($request->userAgent() ?? '')->limit(500)->toString(),
            'metadata' => ['tool' => $tool->name, ...$metadata],
        ]);
    }
}
