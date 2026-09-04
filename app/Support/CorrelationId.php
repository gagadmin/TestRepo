<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The correlation identifier for the current unit of work.
 *
 * "Unit of work" is a web request, a console command run, or a queued job.
 * Holding it in one container-bound object rather than on the request means the
 * queue and console paths can read and set it the same way the HTTP middleware
 * does, which is what lets an identifier follow a scheduled report from the
 * command that dispatched it through to the worker that delivered it.
 *
 * Setting the identifier also pushes it into the log context, so callers never
 * have to remember to do both.
 */
class CorrelationId
{
    /**
     * Accepted shape for an identifier supplied from outside the application.
     *
     * Deliberately narrow: the value is written into log files, so anything
     * carrying newlines or control characters would let a caller forge log
     * entries.
     */
    public const PATTERN = '/^[A-Za-z0-9_-]{8,64}$/';

    private ?string $id = null;

    /** The current identifier, minting one if this unit of work has none yet. */
    public function current(): string
    {
        if ($this->id === null) {
            $this->use((string) Str::uuid());
        }

        return $this->id;
    }

    /**
     * Adopt an identifier, replacing anything unacceptable with a fresh one.
     *
     * A rejected value is replaced rather than cleaned: a rewritten identifier
     * is harder to reason about than an obviously new one.
     */
    public function use(?string $id): string
    {
        $this->id = $this->acceptable($id) ? $id : (string) Str::uuid();

        Log::withContext(['correlation_id' => $this->id]);

        return $this->id;
    }

    /**
     * Drop the identifier and its log context.
     *
     * Called between queued jobs so one job's identifier cannot leak into the
     * next job handled by the same long-running worker.
     */
    public function reset(): void
    {
        $this->id = null;

        Log::withoutContext();
    }

    public function acceptable(?string $id): bool
    {
        return $id !== null && preg_match(self::PATTERN, $id) === 1;
    }
}
