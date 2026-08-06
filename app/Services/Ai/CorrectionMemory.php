<?php

namespace App\Services\Ai;

use App\Models\AiCorrection;
use App\Models\AiToolFailure;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Curated corrections injected into the prompt, plus recent connector failures.
 *
 * What this is NOT: training. A hosted model's weights never change. What
 * produces learning-like behaviour is retrieval — approved corrections relevant
 * to the current question are added to the instructions, so the model has the
 * right guidance in context.
 *
 * Only `approved` corrections are ever used. Injecting unreviewed user text
 * would hand any account a way to steer every other user's answers, which is
 * prompt injection with a database behind it.
 */
class CorrectionMemory
{
    /**
     * Approved corrections relevant to a question, most useful first.
     *
     * Relevance is keyword overlap rather than embeddings: this corpus is small
     * and curated, so the cost and moving parts of a vector store are not
     * justified yet. If the corpus grows past a few hundred entries, switch to
     * embeddings — the interface here does not need to change.
     */
    public function relevantTo(string $question, array $availableTools = []): Collection
    {
        if (! config('ai.corrections.enabled', true)) {
            return collect();
        }

        $limit = (int) config('ai.corrections.max_injected', 8);
        $terms = $this->keywords($question);

        $corrections = AiCorrection::query()
            ->approved()
            ->orderByDesc('applied_count')
            ->orderByDesc('reviewed_at')
            // Bounded read: the decrypted text has to be scored in PHP because
            // the columns are encrypted and therefore not searchable in SQL.
            ->limit(200)
            ->get();

        return $corrections
            ->filter(function (AiCorrection $correction) use ($availableTools) {
                $scoped = $correction->applies_to_tools;

                // A correction scoped to tools the caller cannot use is noise.
                if (blank($scoped) || blank($availableTools)) {
                    return true;
                }

                return array_intersect($scoped, $availableTools) !== [];
            })
            ->map(fn (AiCorrection $correction) => [
                'model' => $correction,
                'score' => $this->score($correction, $terms),
            ])
            ->filter(fn (array $row) => $row['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->pluck('model')
            ->values();
    }

    /**
     * Render corrections as a prompt fragment.
     *
     * Framed as operator-supplied guidance and explicitly separated from tool
     * output, so the model does not treat it as retrieved fact.
     */
    public function asPromptFragment(Collection $corrections): string
    {
        if ($corrections->isEmpty()) {
            return '';
        }

        $lines = $corrections
            ->map(function (AiCorrection $correction) {
                $topic = filled($correction->topic) ? "[{$correction->topic}] " : '';

                return "- {$topic}{$correction->correction}";
            })
            ->implode("\n");

        return <<<FRAGMENT

        Reviewed guidance from previous corrections. An administrator has approved each
        of these after an earlier answer was wrong. Apply them, but never present them
        as retrieved data — they are guidance, not tool results:
        {$lines}
        FRAGMENT;
    }

    /**
     * Recent unresolved connector failures, so the assistant can explain why a
     * source did not answer instead of concluding it does not exist.
     */
    public function recentFailures(array $toolNames = []): Collection
    {
        return AiToolFailure::query()
            ->unresolved()
            ->recent(6)
            ->when($toolNames !== [], fn ($query) => $query->whereIn('tool_name', $toolNames))
            ->orderByDesc('last_failed_at')
            ->limit(10)
            ->get();
    }

    public function failuresAsPromptFragment(Collection $failures): string
    {
        if ($failures->isEmpty()) {
            return '';
        }

        $lines = $failures
            ->map(function (AiToolFailure $failure) {
                $reason = match ($failure->reason) {
                    AiToolFailure::REASON_NO_SOURCE => 'no connected source',
                    AiToolFailure::REASON_NOT_AUTHORIZED => 'connected but not visible to this user',
                    AiToolFailure::REASON_MISCONFIGURED => 'misconfigured',
                    default => 'upstream error',
                };

                return "- {$failure->tool_name}: {$reason} — {$failure->message}";
            })
            ->implode("\n");

        return <<<FRAGMENT

        Recent connector problems. If a question needs one of these, say the connector
        is configured but currently failing and give this reason. Do not claim the
        capability does not exist:
        {$lines}
        FRAGMENT;
    }

    /**
     * Capture a reported problem. Pending until an administrator approves it, so
     * it has no effect on anyone's answers yet.
     */
    public function report(
        User $reporter,
        string $question,
        ?string $incorrectAnswer,
        string $correction,
        ?int $conversationId = null,
        ?string $topic = null,
        array $appliesToTools = [],
    ): AiCorrection {
        return AiCorrection::create([
            'question' => $question,
            'incorrect_answer' => $incorrectAnswer,
            'correction' => $correction,
            'topic' => $topic,
            'applies_to_tools' => $appliesToTools ?: null,
            'status' => 'pending',
            'reported_by' => $reporter->id,
            'conversation_id' => $conversationId,
        ]);
    }

    /**
     * Note that corrections were used, so low-value entries can be pruned.
     */
    public function markApplied(Collection $corrections): void
    {
        if ($corrections->isEmpty()) {
            return;
        }

        AiCorrection::whereIn('id', $corrections->pluck('id'))
            ->update([
                'applied_count' => \DB::raw('applied_count + 1'),
                'last_applied_at' => now(),
            ]);
    }

    /**
     * Overlapping significant words between the question and a correction.
     */
    private function score(AiCorrection $correction, array $terms): int
    {
        if ($terms === []) {
            return 1;
        }

        $haystack = Str::lower(
            $correction->question.' '.$correction->correction.' '.$correction->topic
        );

        $score = 0;

        foreach ($terms as $term) {
            if (str_contains($haystack, $term)) {
                // Longer words are more discriminating than short ones.
                $score += mb_strlen($term) >= 6 ? 3 : 1;
            }
        }

        return $score;
    }

    /**
     * @return array<int, string>
     */
    private function keywords(string $question): array
    {
        $stopWords = [
            'the', 'and', 'for', 'are', 'how', 'many', 'what', 'when', 'who', 'why',
            'from', 'with', 'this', 'that', 'was', 'were', 'has', 'have', 'had',
            'can', 'you', 'our', 'all', 'any', 'get', 'show', 'give', 'tell',
            'about', 'into', 'over', 'much', 'does', 'did', 'will', 'would',
        ];

        return collect(preg_split('/[^\p{L}\p{N}]+/u', Str::lower($question)))
            ->filter(fn (?string $word) => filled($word)
                && mb_strlen($word) >= 3
                && ! in_array($word, $stopWords, true))
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }
}
