<?php

namespace Tests\Feature;

use App\Models\LoginThrottle;
use App\Models\SecurityEvent;
use App\Models\SecurityScan;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Retention purge for security telemetry (`security:purge-history`).
 *
 * The command runs unattended at 03:30 daily and deletes rows, so the tests
 * that matter most are the ones asserting what it must NOT remove: an open
 * finding of any age, a resolved finding still inside its window, an active
 * lockout, and a resolved finding carrying no resolution date to judge it by.
 */
class SecurityRetentionTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------
     | Events
     |------------------------------------------------------------------ */

    public function test_it_purges_resolved_and_false_positive_events_beyond_the_window(): void
    {
        $this->event('old-resolved', 'resolved', now()->subDays(400));
        $this->event('old-false-positive', 'false_positive', now()->subDays(400));

        $this->artisan('security:purge-history')->assertSuccessful();

        $this->assertSame(0, SecurityEvent::count());
    }

    public function test_it_never_purges_an_open_finding_however_old(): void
    {
        // The invariant the command exists to protect: nothing unattended
        // disappears silently, regardless of age.
        $this->event('ancient-open', 'open', null, now()->subYears(5));
        $this->event('ancient-acknowledged', 'acknowledged', null, now()->subYears(5));

        $this->artisan('security:purge-history')->assertSuccessful();

        $this->assertSame(2, SecurityEvent::count());
    }

    public function test_it_retains_a_resolved_finding_inside_the_window(): void
    {
        $this->event('recent-resolved', 'resolved', now()->subDays(30));

        $this->artisan('security:purge-history')->assertSuccessful();

        $this->assertSame(1, SecurityEvent::count());
    }

    public function test_it_retains_a_resolved_finding_with_no_resolution_date(): void
    {
        // A row whose `resolved_at` was never written cannot be aged, so the
        // comparison excludes it and it is kept. Deleting undateable rows would
        // be the unsafe reading of the same situation.
        $this->event('resolved-undated', 'resolved', null);

        $this->artisan('security:purge-history')->assertSuccessful();

        $this->assertSame(1, SecurityEvent::count());
    }

    public function test_the_event_window_follows_configuration(): void
    {
        Config::set('security.retention.resolved_event_days', 10);
        $this->event('resolved-20-days', 'resolved', now()->subDays(20));

        $this->artisan('security:purge-history')->assertSuccessful();

        $this->assertSame(0, SecurityEvent::count());
    }

    public function test_the_event_option_overrides_configuration(): void
    {
        Config::set('security.retention.resolved_event_days', 365);
        $this->event('resolved-20-days', 'resolved', now()->subDays(20));

        $this->artisan('security:purge-history', ['--event-days' => 10])->assertSuccessful();

        $this->assertSame(0, SecurityEvent::count());
    }

    /* ------------------------------------------------------------------
     | Scans
     |------------------------------------------------------------------ */

    public function test_it_purges_scan_history_beyond_the_window_and_keeps_the_rest(): void
    {
        $this->scan(now()->subDays(120));
        $keep = $this->scan(now()->subDays(10));

        $this->artisan('security:purge-history')->assertSuccessful();

        $this->assertSame([$keep], SecurityScan::query()->pluck('id')->all());
    }

    public function test_the_scan_option_overrides_configuration(): void
    {
        Config::set('security.retention.scan_history_days', 365);
        $this->scan(now()->subDays(30));

        $this->artisan('security:purge-history', ['--scan-days' => 7])->assertSuccessful();

        $this->assertSame(0, SecurityScan::count());
    }

    /* ------------------------------------------------------------------
     | Login throttles
     |------------------------------------------------------------------ */

    public function test_it_purges_expired_throttle_rows_but_never_an_active_lock(): void
    {
        // Dropping a live lockout would hand an attacker a fresh failure budget
        // every night, so the active row has to survive the purge.
        $expired = $this->throttle('expired', now()->subDays(3), now()->subDays(2));
        $active = $this->throttle('active', now()->subDays(3), now()->addHour());
        $recent = $this->throttle('recent', now()->subMinutes(5), null);

        $this->artisan('security:purge-history')->assertSuccessful();

        $surviving = LoginThrottle::query()->pluck('identifier_hash')->sort()->values()->all();

        $this->assertSame(['active', 'recent'], $surviving);
        $this->assertDatabaseMissing('login_throttles', ['id' => $expired]);
        $this->assertDatabaseHas('login_throttles', ['id' => $active]);
        $this->assertDatabaseHas('login_throttles', ['id' => $recent]);
    }

    /* ------------------------------------------------------------------
     | Reporting
     |------------------------------------------------------------------ */

    public function test_it_reports_what_it_removed(): void
    {
        $this->event('old-resolved', 'resolved', now()->subDays(400));
        $this->scan(now()->subDays(120));
        $this->throttle('expired', now()->subDays(3), now()->subDays(2));

        $this->artisan('security:purge-history')
            ->expectsOutputToContain('Purged 1 resolved security event(s)')
            ->expectsOutputToContain('Purged 1 scan record(s)')
            ->expectsOutputToContain('Purged 1 expired login throttle row(s)')
            ->assertSuccessful();
    }

    public function test_it_succeeds_with_nothing_to_purge(): void
    {
        $this->artisan('security:purge-history')
            ->expectsOutputToContain('Purged 0 resolved security event(s)')
            ->assertSuccessful();
    }

    /* ------------------------------------------------------------------
     | Helpers
     |------------------------------------------------------------------ */

    private function event(
        string $fingerprint,
        string $status,
        ?DateTimeInterface $resolvedAt,
        ?DateTimeInterface $detectedAt = null,
    ): SecurityEvent {
        $detectedAt ??= now()->subDays(400);

        return SecurityEvent::create([
            'detector' => 'test',
            'category' => 'access',
            'severity' => 'medium',
            'title' => 'Test finding',
            'description' => 'Created by the retention test.',
            'status' => $status,
            'fingerprint' => $fingerprint,
            'first_detected_at' => $detectedAt,
            'last_detected_at' => $detectedAt,
            'resolved_at' => $resolvedAt,
        ]);
    }

    private function scan(DateTimeInterface $createdAt): int
    {
        $scan = SecurityScan::create([
            'trigger' => 'scheduled',
            'status' => 'completed',
        ]);

        // `created_at` is what the purge compares against, and Eloquent stamps
        // it on insert, so it is rewritten directly rather than passed in.
        DB::table('security_scans')->where('id', $scan->id)->update(['created_at' => $createdAt]);

        return $scan->id;
    }

    private function throttle(string $hash, DateTimeInterface $lastFailedAt, ?DateTimeInterface $lockedUntil): int
    {
        return LoginThrottle::create([
            'identifier_hash' => $hash,
            'ip_address' => '203.0.113.10',
            'stage' => 'password',
            'failure_count' => 3,
            'first_failed_at' => $lastFailedAt,
            'last_failed_at' => $lastFailedAt,
            'locked_until' => $lockedUntil,
        ])->id;
    }
}
