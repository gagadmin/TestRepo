<?php

namespace Tests\Feature;

use App\Mail\SecurityAlertMail;
use App\Models\SecurityEvent;
use App\Services\Security\SecurityAlertDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Security alert delivery (`SecurityAlertDispatcher`).
 *
 * The invariant under test throughout is the `alerted` flag. A finding marked
 * alerted is never announced again, so marking one when nothing was actually
 * delivered turns a failed notification into a permanently silent finding —
 * the worst outcome available to a monitoring system. Every failure path below
 * therefore asserts that the flag stays down.
 */
class SecurityAlertDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('security.alerts.enabled', true);
        Config::set('security.alerts.recipients', ['soc@example.test']);
        Config::set('security.alerts.teams_enabled', false);
    }

    /* ------------------------------------------------------------------
     | Email delivery
     |------------------------------------------------------------------ */

    public function test_it_emails_the_configured_recipients_and_marks_the_findings(): void
    {
        Mail::fake();
        Config::set('security.alerts.recipients', ['soc@example.test', 'ciso@example.test']);
        $events = $this->events(2);

        $result = $this->dispatch($events);

        Mail::assertSent(SecurityAlertMail::class, function (SecurityAlertMail $mail) {
            return $mail->hasTo('soc@example.test') && $mail->hasTo('ciso@example.test');
        });

        $this->assertSame(2, $result['sent']);
        $this->assertSame('sent to 2 recipient(s)', $result['channels']['email']);
        $this->assertNull($result['skipped']);
        $this->assertSame(2, SecurityEvent::where('alerted', true)->count());
    }

    public function test_a_critical_finding_is_flagged_in_the_subject(): void
    {
        // The subject is the only part of the alert a recipient sees before
        // opening it, so severity has to survive into it.
        Mail::fake();
        $events = $this->events(1, 'critical');

        $this->dispatch($events);

        Mail::assertSent(SecurityAlertMail::class, function (SecurityAlertMail $mail) {
            return str_starts_with($mail->envelope()->subject, '[CRITICAL]');
        });
    }

    public function test_a_failed_email_leaves_the_findings_unalerted(): void
    {
        // The flag must not be set on a delivery that did not happen, or the
        // finding is never announced again.
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP refused the connection'));
        $events = $this->events(2);

        $result = $this->dispatch($events);

        $this->assertSame(0, $result['sent']);
        $this->assertStringStartsWith('failed: SMTP refused', $result['channels']['email']);
        $this->assertSame(0, SecurityEvent::where('alerted', true)->count());
    }

    /* ------------------------------------------------------------------
     | Nothing to do
     |------------------------------------------------------------------ */

    public function test_it_does_nothing_when_there_are_no_findings(): void
    {
        Mail::fake();

        $result = $this->dispatch(collect());

        Mail::assertNothingSent();
        $this->assertSame(0, $result['sent']);
        $this->assertSame('No events required alerting.', $result['skipped']);
    }

    public function test_it_does_nothing_when_alerting_is_disabled(): void
    {
        Mail::fake();
        Config::set('security.alerts.enabled', false);
        $events = $this->events(1);

        $result = $this->dispatch($events);

        Mail::assertNothingSent();
        $this->assertSame('Security alerting is disabled.', $result['skipped']);
        $this->assertSame(0, SecurityEvent::where('alerted', true)->count());
    }

    public function test_no_recipients_skips_email_and_leaves_the_findings_unalerted(): void
    {
        Mail::fake();
        Config::set('security.alerts.recipients', []);
        $events = $this->events(1);

        $result = $this->dispatch($events);

        Mail::assertNothingSent();
        $this->assertStringStartsWith('skipped:', $result['channels']['email']);
        $this->assertSame(0, SecurityEvent::where('alerted', true)->count());
    }

    /* ------------------------------------------------------------------
     | Teams channel
     |------------------------------------------------------------------ */

    public function test_teams_is_not_contacted_while_the_channel_is_disabled(): void
    {
        Mail::fake();
        Http::fake();
        $this->dispatch($this->events(1));

        Http::assertNothingSent();
    }

    public function test_a_plaintext_teams_webhook_is_refused(): void
    {
        // Alert payloads name findings and severities. The transport stays
        // HTTPS, matching the platform's outbound integration policy.
        Mail::fake();
        Http::fake();
        Config::set('security.alerts.teams_enabled', true);
        Config::set('services.teams.webhook_url', 'http://teams.example.test/hook');

        $result = $this->dispatch($this->events(1));

        Http::assertNothingSent();
        $this->assertSame('skipped: no secure Teams webhook configured', $result['channels']['teams']);
    }

    public function test_a_secure_teams_webhook_receives_the_findings(): void
    {
        Mail::fake();
        Http::fake(fn () => Http::response([], 200));
        Config::set('security.alerts.teams_enabled', true);
        Config::set('services.teams.webhook_url', 'https://teams.example.test/hook');

        $result = $this->dispatch($this->events(1, 'critical'));

        $this->assertSame('sent', $result['channels']['teams']);
        Http::assertSent(fn ($request) => $request->url() === 'https://teams.example.test/hook'
            && $request['type'] === 'message');
    }

    public function test_a_delivered_teams_message_alerts_the_findings_even_when_email_fails(): void
    {
        // "At least one channel delivered" is the rule. A finding announced on
        // Teams has reached a human, so re-announcing it would be noise.
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP down'));
        Http::fake(fn () => Http::response([], 200));
        Config::set('security.alerts.teams_enabled', true);
        Config::set('services.teams.webhook_url', 'https://teams.example.test/hook');

        $result = $this->dispatch($this->events(2));

        $this->assertSame(2, $result['sent']);
        $this->assertSame(2, SecurityEvent::where('alerted', true)->count());
    }

    public function test_both_channels_failing_leaves_the_findings_unalerted(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP down'));
        Http::fake(fn () => Http::response([], 500));
        Config::set('security.alerts.teams_enabled', true);
        Config::set('services.teams.webhook_url', 'https://teams.example.test/hook');

        $result = $this->dispatch($this->events(2));

        $this->assertSame(0, $result['sent']);
        $this->assertStringStartsWith('failed:', $result['channels']['teams']);
        $this->assertSame(0, SecurityEvent::where('alerted', true)->count());
    }

    /* ------------------------------------------------------------------
     | Helpers
     |------------------------------------------------------------------ */

    private function dispatch(Collection $events): array
    {
        return app(SecurityAlertDispatcher::class)->dispatch($events);
    }

    private function events(int $count, string $severity = 'high'): Collection
    {
        return collect(range(1, $count))->map(fn (int $i) => SecurityEvent::create([
            'detector' => 'test',
            'category' => 'access',
            'severity' => $severity,
            'title' => "Finding {$i}",
            'description' => 'Created by the alert dispatch test.',
            'status' => 'open',
            'fingerprint' => "alert-test-{$severity}-{$i}",
            'first_detected_at' => now(),
            'last_detected_at' => now(),
        ]));
    }
}
