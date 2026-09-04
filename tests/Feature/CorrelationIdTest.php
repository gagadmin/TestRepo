<?php

namespace Tests\Feature;

use App\Http\Middleware\AssignCorrelationId;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Request correlation.
 *
 * The application logs provider, detector, and delivery failures, but nothing
 * tied those lines to the request that caused them. The identifier has to reach
 * three places to be useful: the log context, the response, and anything that
 * reads the request.
 */
class CorrelationIdTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A plain route inside the `web` group.
     *
     * Deliberately not `/up`: the health endpoint is registered outside the web
     * middleware group, so it carries no correlation identifier and is not a
     * representative target for these assertions.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/test-correlation', fn () => response()->noContent());
    }

    public function test_every_response_carries_a_correlation_id(): void
    {
        $response = $this->get('/test-correlation');

        $id = $response->headers->get(AssignCorrelationId::HEADER);
        $this->assertNotNull($id);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
    }

    public function test_two_requests_receive_different_identifiers(): void
    {
        $first = $this->get('/test-correlation')->headers->get(AssignCorrelationId::HEADER);
        $second = $this->get('/test-correlation')->headers->get(AssignCorrelationId::HEADER);

        $this->assertNotSame($first, $second);
    }

    public function test_an_upstream_identifier_is_honoured(): void
    {
        // A value assigned by a gateway or load balancer should survive, so one
        // request can be followed across both sets of logs.
        $response = $this->withHeader(AssignCorrelationId::HEADER, 'edge-abc123def456')->get('/test-correlation');

        $this->assertSame('edge-abc123def456', $response->headers->get(AssignCorrelationId::HEADER));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function unsafeIdentifiers(): array
    {
        return [
            'log injection' => ["abc123def456\nERROR fabricated line"],
            'carriage return' => ["abc123def456\rmore"],
            'too short' => ['abc'],
            'too long' => [str_repeat('a', 65)],
            'spaces' => ['abc 123 def 456'],
            'punctuation' => ['abc<script>alert(1)</script>'],
            'empty' => [''],
        ];
    }

    #[DataProvider('unsafeIdentifiers')]
    public function test_an_unsafe_identifier_is_replaced_rather_than_trusted(string $supplied): void
    {
        /*
         * The identifier is written into log files. Accepting a caller-supplied
         * value unchecked would let anyone forge log lines, so anything that is
         * not a short plain token is discarded and a fresh one issued.
         */
        $response = $this->withHeader(AssignCorrelationId::HEADER, $supplied)->get('/test-correlation');

        $issued = $response->headers->get(AssignCorrelationId::HEADER);
        $this->assertNotSame($supplied, $issued);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $issued);
    }

    public function test_log_lines_written_during_the_request_carry_the_identifier(): void
    {
        Route::middleware('web')->get('/test-correlation-logging', function () {
            Log::warning('Something went wrong.');

            return response()->noContent();
        });

        $captured = [];
        Log::listen(function ($message) use (&$captured) {
            $captured[] = $message->context;
        });

        $response = $this->get('/test-correlation-logging');
        $id = $response->headers->get(AssignCorrelationId::HEADER);

        $this->assertNotEmpty($captured, 'The route did not write a log line.');
        $this->assertSame($id, $captured[0]['correlation_id'] ?? null);
    }

    public function test_the_request_carries_the_identifier_for_application_code(): void
    {
        Route::middleware('web')->get('/test-correlation-attribute', fn () => response()->json([
            'id' => request()->attributes->get('correlation_id'),
        ]));

        $response = $this->get('/test-correlation-attribute');

        $this->assertSame(
            $response->headers->get(AssignCorrelationId::HEADER),
            $response->json('id'),
        );
    }

    public function test_an_authenticated_json_request_is_also_correlated(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user)->getJson('/api/bootstrap');

        $this->assertNotNull($response->headers->get(AssignCorrelationId::HEADER));
    }
}
