<?php

namespace App\Providers;

use App\Support\CorrelationId;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One identifier per unit of work: request, command run, or queued job.
        $this->app->singleton(CorrelationId::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->correlateWorkOutsideRequests();
        $this->recordQueueWaitTime();
    }

    /**
     * Report how long each job waited before a worker picked it up.
     *
     * Delivery outcomes are already durable in `report_schedule_runs`, and
     * connector outcomes in `integration_runs`, but nothing measured the gap
     * between queueing a job and starting it. That gap is the signal that says
     * whether workers are keeping up or have stopped entirely — the silent
     * failure KI-003 describes, where a scheduled report is queued, never runs,
     * and nothing says so.
     *
     * The line carries no payload, only the job name, queue, attempt, and the
     * wait in milliseconds; the correlation identifier is attached already.
     */
    private function recordQueueWaitTime(): void
    {
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $queuedAt = $event->job->payload()['queued_at'] ?? null;

            if (! is_numeric($queuedAt)) {
                // Queued before this was added, or by something that does not
                // route through the payload hook. Nothing to measure.
                return;
            }

            Log::info('Queued job started.', [
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'attempt' => $event->job->attempts(),
                'queue_wait_ms' => (int) round((microtime(true) - (float) $queuedAt) * 1000),
            ]);
        });
    }

    /**
     * Extend request correlation to the scheduler and the queue.
     *
     * HTTP requests are correlated by `AssignCorrelationId`. Scheduled commands
     * and queued jobs run outside any request, so without this the logs written
     * by a scheduled report delivery or an SEO snapshot could not be joined to
     * anything — which was the gap left when correlation was introduced.
     *
     * The chain that matters: `reports:dispatch-schedules` mints an identifier
     * when it starts, the identifier is written into each job payload as the
     * job is queued, and the worker adopts it while handling that job. A
     * schedule dispatched from the interface instead carries the identifier of
     * the request that asked for it.
     */
    private function correlateWorkOutsideRequests(): void
    {
        $correlation = fn (): CorrelationId => $this->app->make(CorrelationId::class);

        // A console run is its own unit of work.
        Event::listen(CommandStarting::class, function () use ($correlation): void {
            $correlation()->current();
        });

        // Carry the dispatching identifier into the payload.
        Queue::createPayloadUsing(fn (): array => [
            'correlation_id' => $correlation()->current(),
            // Read back on JobProcessing to report how long the job waited.
            'queued_at' => microtime(true),
        ]);

        Event::listen(JobProcessing::class, function (JobProcessing $event) use ($correlation): void {
            /*
             * The reset is belt-and-braces. In the normal cycle the completion
             * listeners below have already cleared the previous job's context,
             * and `use()` overwrites the correlation key regardless — the tests
             * cannot tell the difference, and that was verified rather than
             * assumed. It is kept because a worker handles many jobs in one
             * process and not every path guarantees a completion event; without
             * it, context pushed by a job that never completed cleanly would be
             * attributed to whatever ran next.
             */
            $correlation()->reset();
            $correlation()->use($event->job->payload()['correlation_id'] ?? null);
        });

        foreach ([JobProcessed::class, JobFailed::class] as $finished) {
            Event::listen($finished, function () use ($correlation): void {
                $correlation()->reset();
            });
        }
    }
}
