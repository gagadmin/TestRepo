<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DataSource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Security\SecurityMonitor;
use App\Services\Security\SecurityPostureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SecurityMonitoringTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------
     * Authorization
     * ------------------------------------------------------------------ */

    public function test_security_dashboard_requires_the_security_view_permission(): void
    {
        $user = $this->userWithRole('analyst', ['reports.view'], 'Information Technology');

        $this->actingAs($user)->getJson('/api/security')->assertForbidden();
    }

    public function test_security_dashboard_denies_permitted_user_outside_it_or_security_roles(): void
    {
        // Has the permission, but sits in Finance and holds no privileged role.
        $user = $this->userWithRole('manager', ['security.view'], 'Finance');

        $this->actingAs($user)
            ->getJson('/api/security')
            ->assertForbidden();

        // The denial itself is recorded for investigation.
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event' => 'security.access_denied',
        ]);
    }

    public function test_it_department_member_with_permission_can_read_the_dashboard(): void
    {
        $user = $this->userWithRole('it_analyst', ['security.view'], 'Information Technology');

        $this->actingAs($user)
            ->getJson('/api/security')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'overview' => ['security_score', 'open_incidents', 'compliance_percentage'],
                    'threats', 'identity', 'incidents', 'compliance', 'assets',
                    'vulnerability_management', 'endpoint_security',
                    'email_security', 'cloud_security', 'meta',
                ],
            ]);
    }

    public function test_security_officer_outside_it_department_can_read_the_dashboard(): void
    {
        $user = $this->userWithRole('security_officer', ['security.view'], 'Corporate Services');

        $this->actingAs($user)->getJson('/api/security')->assertOk();
    }

    public function test_managing_events_requires_the_manage_permission(): void
    {
        $viewer = $this->userWithRole('it_viewer', ['security.view'], 'Information Technology');
        $event = $this->event();

        $this->actingAs($viewer)
            ->putJson("/api/security/events/{$event->id}", [
                'status' => 'resolved',
                'resolution_note' => 'Handled.',
            ])
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------
     * Detectors
     * ------------------------------------------------------------------ */

    public function test_brute_force_detector_records_a_finding_for_a_noisy_address(): void
    {
        Config::set('security.detection.brute_force.failures_per_ip', 5);

        for ($i = 0; $i < 6; $i++) {
            $this->failedLogin('203.0.113.10', 'fingerprint-a');
        }

        app(SecurityMonitor::class)->scan();

        $event = SecurityEvent::where('detector', 'brute_force')
            ->where('ip_address', '203.0.113.10')
            ->first();

        $this->assertNotNull($event, 'Expected a brute force finding.');
        $this->assertSame('open', $event->status);
        $this->assertSame(6, $event->evidence['failed_attempts']);
    }

    public function test_credential_stuffing_detector_flags_many_accounts_from_one_address(): void
    {
        Config::set('security.detection.credential_stuffing.distinct_accounts_per_ip', 3);

        foreach (['a', 'b', 'c', 'd'] as $account) {
            $this->failedLogin('198.51.100.7', "fingerprint-{$account}");
        }

        app(SecurityMonitor::class)->scan();

        $event = SecurityEvent::where('detector', 'credential_stuffing')->first();

        $this->assertNotNull($event);
        $this->assertSame('critical', $event->severity);
        $this->assertGreaterThanOrEqual(3, $event->evidence['distinct_accounts_targeted']);
    }

    public function test_repeat_detections_increment_occurrences_instead_of_duplicating(): void
    {
        Config::set('security.detection.brute_force.failures_per_ip', 3);

        for ($i = 0; $i < 4; $i++) {
            $this->failedLogin('203.0.113.55', 'fingerprint-a');
        }

        $monitor = app(SecurityMonitor::class);
        $monitor->scan();
        $monitor->scan();

        $events = SecurityEvent::where('detector', 'brute_force')
            ->where('ip_address', '203.0.113.55')
            ->get();

        $this->assertCount(1, $events, 'A repeating condition must not create duplicate rows.');
        $this->assertSame(2, $events->first()->occurrences);
    }

    public function test_plaintext_http_integration_is_reported_as_credential_exposure(): void
    {
        DataSource::create([
            'name' => 'Legacy ERP',
            'type' => 'erp',
            'base_url' => 'http://legacy.internal.example.com',
            'status' => 'connected',
            'owner_id' => User::factory()->create()->id,
        ]);

        app(SecurityMonitor::class)->scan();

        $event = SecurityEvent::where('detector', 'credential_exposure')->first();

        $this->assertNotNull($event);
        $this->assertSame('high', $event->severity);
        $this->assertStringContainsString('Legacy ERP', $event->title);
    }

    public function test_configuration_drift_detects_debug_enabled_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');
        Config::set('app.debug', true);

        app(SecurityMonitor::class)->scan();

        $event = SecurityEvent::where('fingerprint', 'configuration_drift:app_debug')->first();

        $this->assertNotNull($event);
        $this->assertSame('critical', $event->severity);
    }

    public function test_a_scan_records_its_outcome(): void
    {
        $scan = app(SecurityMonitor::class)->scan('manual');

        $this->assertContains($scan->status, ['succeeded', 'partial']);
        $this->assertSame('manual', $scan->trigger);
        $this->assertGreaterThan(0, $scan->detectors_run);
        $this->assertNotNull($scan->finished_at);
    }

    /* ------------------------------------------------------------------
     * Lifecycle and honesty guarantees
     * ------------------------------------------------------------------ */

    public function test_resolving_an_event_requires_a_note_and_records_the_actor(): void
    {
        $manager = $this->userWithRole(
            'security_officer',
            ['security.view', 'security.manage'],
            'Information Technology',
        );
        $event = $this->event();

        $this->actingAs($manager)
            ->putJson("/api/security/events/{$event->id}", ['status' => 'resolved'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('resolution_note');

        $this->actingAs($manager)
            ->putJson("/api/security/events/{$event->id}", [
                'status' => 'resolved',
                'resolution_note' => 'Confirmed as a scheduled penetration test.',
            ])
            ->assertOk();

        $event->refresh();
        $this->assertSame('resolved', $event->status);
        $this->assertSame($manager->id, $event->resolved_by);
        $this->assertNotNull($event->resolved_at);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $manager->id,
            'event' => 'security.event.status_changed',
        ]);
    }

    public function test_a_resolved_finding_reopens_when_the_condition_recurs(): void
    {
        Config::set('security.detection.brute_force.failures_per_ip', 3);

        for ($i = 0; $i < 4; $i++) {
            $this->failedLogin('203.0.113.99', 'fingerprint-a');
        }

        $monitor = app(SecurityMonitor::class);
        $monitor->scan();

        $event = SecurityEvent::where('ip_address', '203.0.113.99')->firstOrFail();
        $event->update(['status' => 'resolved', 'resolved_at' => now(), 'alerted' => true]);

        $monitor->scan();

        $event->refresh();
        $this->assertSame('open', $event->status, 'A recurring condition must reopen the finding.');
        $this->assertNull($event->resolved_at);
        $this->assertFalse($event->alerted, 'A reopened finding must alert again.');
    }

    public function test_unconnected_sections_report_no_metrics_rather_than_fabricated_ones(): void
    {
        Config::set('security.connectors.defender_endpoint', false);

        $payload = app(SecurityPostureService::class)->dashboard();

        $this->assertFalse($payload['endpoint_security']['connected']);
        $this->assertSame([], $payload['endpoint_security']['metrics']);
        $this->assertFalse($payload['cloud_security']['connected']);
        $this->assertSame([], $payload['cloud_security']['metrics']);
    }

    public function test_disabled_mfa_is_reported_as_supported_but_not_enabled(): void
    {
        $identity = app(SecurityPostureService::class)->identityAccess();

        $this->assertTrue($identity['mfa']['supported']);
        $this->assertFalse($identity['mfa']['enabled']);
        $this->assertNull($identity['mfa']['coverage_percentage']);
        $this->assertStringContainsString('disabled by configuration', $identity['mfa']['note']);
    }

    public function test_the_agent_never_deactivates_an_account(): void
    {
        Config::set('security.detection.brute_force.failures_per_ip', 2);
        $victim = User::factory()->create(['is_active' => true]);

        for ($i = 0; $i < 5; $i++) {
            $this->failedLogin('203.0.113.200', 'fingerprint-victim');
        }

        app(SecurityMonitor::class)->scan();

        $this->assertTrue(
            $victim->fresh()->is_active,
            'Detection must never take automated containment action.',
        );
    }

    public function test_alerts_are_not_sent_when_no_recipients_are_configured(): void
    {
        Mail::fake();
        Config::set('security.alerts.recipients', []);
        Config::set('security.detection.brute_force.failures_per_ip', 2);

        for ($i = 0; $i < 3; $i++) {
            $this->failedLogin('203.0.113.77', 'fingerprint-a');
        }

        $this->artisan('security:scan')->assertSuccessful();

        Mail::assertNothingSent();
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    private function failedLogin(string $ip, string $fingerprint): void
    {
        AuditLog::create([
            'user_id' => null,
            'event' => 'auth.login_failed',
            'ip_address' => $ip,
            'user_agent' => 'PHPUnit',
            'metadata' => ['email_fingerprint' => $fingerprint],
        ]);
    }

    private function event(array $attributes = []): SecurityEvent
    {
        return SecurityEvent::create([
            'detector' => 'brute_force',
            'category' => 'threat',
            'severity' => 'high',
            'title' => 'Test finding',
            'description' => 'A finding created for testing.',
            'status' => 'open',
            'fingerprint' => 'test:'.uniqid(),
            'occurrences' => 1,
            'first_detected_at' => now(),
            'last_detected_at' => now(),
            'occurred_at' => now(),
            ...$attributes,
        ]);
    }

    private function userWithRole(string $roleName, array $permissions, ?string $department): User
    {
        $role = Role::firstOrCreate(
            ['name' => $roleName],
            ['label' => ucfirst($roleName)],
        );

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'group' => 'Security'],
            );

            if (! $role->permissions()->where('permissions.id', $permission->id)->exists()) {
                $role->permissions()->attach($permission);
            }
        }

        $user = User::factory()->create([
            'department' => $department,
            'is_active' => true,
        ]);
        $user->roles()->attach($role);

        return $user;
    }
}
