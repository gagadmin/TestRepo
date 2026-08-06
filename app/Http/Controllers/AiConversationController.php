<?php

namespace App\Http\Controllers;

use App\Exceptions\AiProviderException;
use App\Models\Conversation;
use App\Models\DataSource;
use App\Services\Ai\CorrectionMemory;
use App\Services\Ai\ProviderManager;
use App\Services\Ai\ReportingAssistant;
use App\Services\Ai\ToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AiConversationController extends Controller
{
    public function status(Request $request, ProviderManager $providers, ToolRegistry $tools): JsonResponse
    {
        try {
            $provider = $providers->current();
            $providerName = $provider->name();
            $configured = $provider->configured();
        } catch (RuntimeException) {
            $providerName = 'unsupported';
            $configured = false;
        }

        /*
         * Sources are filtered to types an enabled tool can actually read.
         *
         * Previously this listed every connected source regardless of tool
         * coverage, so the chat panel advertised "Freshservice ITSM" as an
         * available source while the assistant had no way to query it. Listing a
         * source the assistant cannot use is worse than listing nothing: the
         * user reasonably concludes the refusal is a bug in their question.
         */
        $reachableTypes = $tools->reachableSourceTypes();

        $sources = DataSource::query()
            ->where('status', 'connected')
            ->whereIn('type', $reachableTypes ?: ['__none__'])
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'owner_id', 'settings'])
            ->filter(fn (DataSource $source) => $source->isAccessibleBy($request->user()))
            ->map(fn (DataSource $source) => [
                'id' => $source->id,
                'name' => $source->name,
                'type' => $source->type,
                'type_label' => config("integrations.types.{$source->type}.label", $source->type),
            ])
            ->values();

        // Connected sources with no enabled tool covering them. Surfaced so an
        // administrator can see the gap instead of discovering it through a
        // refused question.
        $unreachable = DataSource::query()
            ->where('status', 'connected')
            ->whereNotIn('type', $reachableTypes ?: ['__none__'])
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'owner_id', 'settings'])
            ->filter(fn (DataSource $source) => $source->isAccessibleBy($request->user()))
            ->map(fn (DataSource $source) => [
                'id' => $source->id,
                'name' => $source->name,
                'type' => $source->type,
                'type_label' => config("integrations.types.{$source->type}.label", $source->type),
            ])
            ->values();

        return response()->json([
            'configured' => $configured,
            'provider' => $providerName,
            'model' => config('ai.model'),
            'tools' => $tools->names(),
            'sources' => $sources,
            'unreachable_sources' => $unreachable,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Conversation::query()
                ->where('user_id', $request->user()->id)
                ->withCount('messages')
                ->latest('last_message_at')
                ->get()
                ->map(fn (Conversation $conversation) => $this->serializeConversation($conversation)),
        ]);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeOwner($request, $conversation);

        return response()->json([
            'conversation' => $this->serializeConversation($conversation),
            'messages' => $conversation->messages()
                ->whereIn('role', ['user', 'assistant'])
                ->oldest()
                ->get()
                ->map(fn ($message) => $this->serializeMessage($message)),
        ]);
    }

    public function chat(Request $request, ReportingAssistant $assistant): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
            'content' => ['required', 'string', 'max:8000'],
        ]);

        $conversation = null;

        if ($validated['conversation_id'] ?? null) {
            $conversation = Conversation::findOrFail($validated['conversation_id']);
            $this->authorizeOwner($request, $conversation);
        }

        try {
            $result = $assistant->chat($request->user(), $conversation, $validated['content']);
        } catch (AiProviderException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error_code' => $exception->providerCode,
                'retryable' => $exception->retryable,
            ], $exception->clientStatus());
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], str_contains($exception->getMessage(), 'not configured') ? 503 : 422);
        }

        return response()->json([
            'conversation' => $this->serializeConversation($result['conversation']),
            'message' => $this->serializeMessage($result['message']),
        ]);
    }

    /**
     * Report an incorrect answer and suggest the correction.
     *
     * Stored as `pending`. It has no effect on anyone's answers until an
     * administrator approves it — otherwise any account could inject trusted
     * guidance into every other user's prompts.
     */
    public function reportCorrection(Request $request, CorrectionMemory $memory): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
            'question' => ['required', 'string', 'max:2000'],
            'incorrect_answer' => ['nullable', 'string', 'max:8000'],
            'correction' => ['required', 'string', 'min:10', 'max:2000'],
            'topic' => ['nullable', 'string', 'max:120'],
        ]);

        if ($validated['conversation_id'] ?? null) {
            // A user may only attach feedback to their own conversation.
            $this->authorizeOwner($request, Conversation::findOrFail($validated['conversation_id']));
        }

        $correction = $memory->report(
            $request->user(),
            $validated['question'],
            $validated['incorrect_answer'] ?? null,
            $validated['correction'],
            $validated['conversation_id'] ?? null,
            $validated['topic'] ?? null,
        );

        return response()->json([
            'message' => 'Thank you. An administrator will review this before it changes future answers.',
            'id' => $correction->id,
        ], 201);
    }

    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeOwner($request, $conversation);
        $conversation->delete();

        return response()->json(['message' => 'Conversation removed.']);
    }

    private function authorizeOwner(Request $request, Conversation $conversation): void
    {
        if ($conversation->user_id !== $request->user()->id) {
            throw ValidationException::withMessages([
                'conversation' => 'The requested conversation is not available.',
            ]);
        }
    }

    private function serializeConversation(Conversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'status' => $conversation->status,
            'messages_count' => $conversation->messages_count ?? $conversation->messages()->count(),
            'last_message_at' => $conversation->last_message_at,
        ];
    }

    private function serializeMessage($message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'citations' => $message->citations ?? [],
            'tool_calls' => $message->tool_calls ?? [],
            'model' => $message->model,
            'latency_ms' => $message->latency_ms,
            'created_at' => $message->created_at,
        ];
    }
}
