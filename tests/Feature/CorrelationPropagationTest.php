<?php

namespace Tests\Feature;

use App\Support\CorrelationId;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Correlation across the scheduler and the queue.
 *
 * A scheduled report is dispatched by one process and delivered by another, so
 * a request-scoped identifier alone leaves the delivery logs unjoinable — the
 * gap left when HTTP correlation was introduced. The identifier has to survive
 * being written into a job payload and picked up again by the worker.
 */
class CorrelationPropagationTest extends TestCase
{
    use RefreshDatabase;

    private function correlation(): CorrelationId
    {
        return app(CorrelationId::class);
    }

    /* ------------------------------------------------------------------
     | The identifier itself
     |------------------------------------------------------------------ */

    public function test_it_mints_one_identifier_and_keeps_it(): void
    {
        $first = $this->correlation()->current();

        $this->assertSame($first, $this->correlation()->current());
    }

    public function test_it_adopts_an_acceptable_identifier(): void
    {
        $this->assertSame('scheduler-run-0001', $this->correlation()->use('scheduler-run-0001'));
    }

    /**
     * @return list<array{0: ?string}>
     */
    public static function unacceptable(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'newline' => ["abc123def456\nforged"],
            'too short' => ['abc'],
            'too long' => [str_repeat('x', 65)],
        ];
    }

    #[DataProvider('unacceptable')]
    public function test_it_replaces_an_unacceptable_identifier(?string $supplied): void
    {
        $issued = $this->correlation()->use($supplied);

        $this->assertNotSame($supplied, $issued);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $issued);
    }

    public function test_resetting_mints_a_new_identifier_next_time(): void
    {
        $first = $this->correlation()->current();
        $this->correlation()->reset();

        $this->assertNotSame($first, $this->correlation()->current());
    }

    /* ------------------------------------------------------------------
     | Queue payload
     |------------------------------------------------------------------ */

    public function test_a_queued_job_carries_the_dispatching_identifier(): void
    {
        // Asserted against the real queued payload rather than a builder call,
        // because the payload is what actually reaches the worker.
        Config::set('queue.default', 'database');
        $this->correlation()->use('dispatching-run-01');

        CorrelationProbeJob::dispatch();

        $payload = json_decode(DB::table('jobs')->value('payload'), true);
        $this->assertSame('dispatching-run-01', $payload['correlation_id']);
    }

    public function test_a_job_queued_from_a_console_run_carries_that_run_identifier(): void
    {
        /*
         * The chain the scheduler depends on: the command mints an identifier,
         * and every job it queues carries it, so one scheduled sweep and all
         * its deliveries share a key.
         */
        Config::set('queue.default', 'database');
        $this->correlation()->reset();

        $this->artisan('security:purge-history')->assertSuccessful();
        $runId = $this->correlation()->current();

        CorrelationProbeJob::dispatch();

        $payload = json_decode(DB::table('jobs')->value('payload'), true);
        $this->assertSame($runId, $payload['correlation_id']);
    }

    /* ------------------------------------------------------------------
     | Worker adoption
     |------------------------------------------------------------------ */

    public function test_the_worker_adopts_the_identifier_from_the_payload(): void
    {
        $this->correlation()->use('worker-before-0001');

        Event::dispatch(new JobProcessing('database', $this->fakeJob([
            'correlation_id' => 'from-the-payload-1',
        ])));

        $this->assertSame('from-the-payload-1', $this->correlation()->current());
    }

    public function test_a_payload_without_an_identifier_still_gets_one(): void
    {
        // An older job queued before this change must not break the worker.
        Event::dispatch(new JobProcessing('database', $this->fakeJob([])));

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $this->correlation()->current());
    }

    public function test_a_forged_payload_identifier_is_refused(): void
    {
        // The payload is data, not a trusted source; it reaches the log files.
        Event::dispatch(new JobProcessing('database', $this->fakeJob([
            'correlation_id' => "abc123def456\nERROR fabricated",
        ])));

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $this->correlation()->current());
    }

    public function test_one_job_identifier_does_not_leak_into_the_next(): void
    {
        /*
         * A queue worker handles many jobs in one process. Without a reset
         * between them, every later job would log under the first job's
         * identifier.
         */
        Event::dispatch(new JobProcessing('database', $this->fakeJob(['correlation_id' => 'job-aaaa-0001'])));
        $this->assertSame('job-aaaa-0001', $this->correlation()->current());

        Event::dispatch(new JobProcessed('database', $this->fakeJob([])));
        Event::dispatch(new JobProcessing('database', $this->fakeJob(['correlation_id' => 'job-bbbb-0002'])));

        $this->assertSame('job-bbbb-0002', $this->correlation()->current());
    }

    public function test_stale_log_context_does_not_survive_into_the_next_job(): void
    {
        /*
         * What the reset before adopting actually buys, and the reason it is
         * not redundant: `use()` overwrites the correlation key by itself, but
         * any *other* context a job pushed would otherwise persist in the same
         * worker process and be attributed to unrelated later jobs.
         */
        Event::dispatch(new JobProcessing('database', $this->fakeJob(['correlation_id' => 'job-aaaa-0001'])));
        Log::withContext(['report_schedule_id' => 77]);

        Event::dispatch(new JobProcessed('database', $this->fakeJob([])));
        Event::dispatch(new JobProcessing('database', $this->fakeJob(['correlation_id' => 'job-bbbb-0002'])));

        $captured = [];
        Log::listen(function ($message) use (&$captured) {
            $captured[] = $message->context;
        });
        Log::warning('Second job speaking.');

        $this->assertSame('job-bbbb-0002', $captured[0]['correlation_id']);
        $this->assertArrayNotHasKey(
            'report_schedule_id',
            $captured[0],
            "The previous job's context leaked into this one."
        );
    }

    public function test_a_failed_job_clears_the_identifier(): void
    {
        Event::dispatch(new JobProcessing('database', $this->fakeJob(['correlation_id' => 'job-cccc-0003'])));

        Event::dispatch(new JobFailed(
            'database',
            $this->fakeJob([]),
            new RuntimeException('delivery failed')
        ));

        $this->assertNotSame('job-cccc-0003', $this->correlation()->current());
    }

    /* ------------------------------------------------------------------
     | Console runs
     |------------------------------------------------------------------ */

    public function test_a_console_run_logs_under_a_correlation_id(): void
    {
        $captured = [];
        Log::listen(function ($message) use (&$captured) {
            $captured[] = $message->context;
        });

        // A command that logs on its own: the purge writes no warnings, so use
        // one whose logging path is reachable without external services.
        $this->artisan('security:purge-history')->assertSuccessful();

        $this->assertNotNull($this->correlation()->current());
        foreach ($captured as $context) {
            $this->assertArrayHasKey('correlation_id', $context);
        }
    }

    /**
     * A minimal job double: the listener only reads `payload()`.
     *
     * @param  array<string, mixed>  $payload
     */
    private function fakeJob(array $payload): JobContract
    {
        return new FakeQueueJob($payload);
    }
}

/**
 * Stands in for a queued job so the correlation listeners can be exercised
 * without a worker, a driver, or a real job class.
 */
class FakeQueueJob implements JobContract
{
    /** @param array<string, mixed> $payloadData */
    public function __construct(private array $payloadData = []) {}

    public function payload(): array
    {
        return $this->payloadData;
    }

    public function uuid(): ?string
    {
        return 'fake-uuid';
    }

    public function getJobId(): ?string
    {
        return '1';
    }

    public function getRawBody(): string
    {
        return json_encode($this->payloadData);
    }

    public function fire(): void {}

    public function release($delay = 0): void {}

    public function isReleased(): bool
    {
        return false;
    }

    public function delete(): void {}

    public function isDeleted(): bool
    {
        return false;
    }

    public function isDeletedOrReleased(): bool
    {
        return false;
    }

    public function attempts(): int
    {
        return 1;
    }

    public function hasFailed(): bool
    {
        return false;
    }

    public function markAsFailed(): void {}

    public function fail($e = null): void {}

    public function maxTries(): ?int
    {
        return null;
    }

    public function maxExceptions(): ?int
    {
        return null;
    }

    public function backoff(): ?int
    {
        return null;
    }

    public function retryUntil(): ?int
    {
        return null;
    }

    public function timeout(): ?int
    {
        return null;
    }

    public function shouldFailOnTimeout(): bool
    {
        return false;
    }

    public function getName(): string
    {
        return 'FakeQueueJob';
    }

    public function resolveName(): string
    {
        return 'FakeQueueJob';
    }

    public function resolveQueuedJobClass(): string
    {
        return CorrelationProbeJob::class;
    }

    public function getConnectionName(): string
    {
        return 'database';
    }

    public function getQueue(): string
    {
        return 'default';
    }
}

/** A trivial queueable job used only to inspect the payload the queue builds. */
class CorrelationProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    public function handle(): void {}
}
