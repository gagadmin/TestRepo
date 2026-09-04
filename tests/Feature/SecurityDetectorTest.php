<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Security\SecurityMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The security detectors that had no coverage.
 *
 * `SecurityMonitoringTest` exercised brute force, credential stuffing,
 * configuration drift, and credential exposure. The remaining seven ran
 * unverified on a five-minute schedule, which is how the two defects fixed
 * alongside these tests survived: a detection branch that could never fire, and
 * an ungrouped SQL condition that let one branch escape its time window.
 *
 * Each detector gets a positive case and, where it is cheap, the negative that
 * proves it is not simply flagging everything.
 */
class SecurityDetectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('security.alerts.enabled', false);
        // Keep unrelated detectors quiet so each assertion is about one thing.
        Config::set('security.two_factor.enabled', true);
        Config::set('security.detection.after_hours.enabled', false);
    }

    /* ------------------------------------------------------------------
     | Session anomaly
     |------------------------------------------------------------------ */

    public function test_it_flags_a_user_signing_in_from_many_addresses(): void
    {
        Config::set('security.detection.session_anomaly.distinct_ips_per_user', 3);
        $user = $this->user('Mara Iqbal');

        foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3'] as $ip) {
            $this->audit('auth.login', $user, ['ip_address' => $ip]);
        }

        $this->scan();

        $finding = $this->finding('session_anomaly');
        $this->assertNotNull($finding);
        $this->assertSame(3, $finding->evidence['distinct_sources']);
    }

    public function test_a_user_on_one_address_is_not_flagged(): void
    {
        Config::set('security.detection.session_anomaly.distinct_ips_per_user', 3);
        $user = $this->user('Steady Sam');

        for ($i = 0; $i < 5; $i++) {
            $this->audit('auth.login', $user, ['ip_address' => '203.0.113.9']);
        }

        $this->scan();

        $this->assertNull($this->finding('session_anomaly'));
    }

    /* ------------------------------------------------------------------
     | Privilege escalation
     |------------------------------------------------------------------ */

    public function test_it_flags_a_newly_granted_administrator_role(): void
    {
        $actor = $this->user('Admin Ada');
        $subject = $this->user('Subject Sid');

        $this->accessChange($actor, $subject, ['analyst'], ['analyst', 'administrator']);
        $this->scan();

        $finding = $this->finding('privilege_escalation');
        $this->assertNotNull($finding);
        $this->assertSame('high', $finding->severity);
        $this->assertSame(['administrator'], $finding->evidence['roles_gained']);
    }

    public function test_it_flags_a_reactivated_account(): void
    {
        /*
         * Regression: the reactivation branch read `is_active` from the roles
         * collection rather than from the metadata, so the comparison was
         * always null !== false and a deactivated account being re-enabled was
         * never reported.
         */
        $actor = $this->user('Admin Ada');
        $subject = $this->user('Dormant Dee');

        $this->accessChange($actor, $subject, ['analyst'], ['analyst'], activeBefore: false, activeAfter: true);
        $this->scan();

        $finding = $this->finding('privilege_escalation');
        $this->assertNotNull($finding, 'Re-enabling a deactivated account must be reported.');
        $this->assertTrue($finding->evidence['reactivated']);
        $this->assertSame('medium', $finding->severity);
        $this->assertStringContainsString('re-enabled', $finding->title);
    }

    public function test_an_ordinary_role_change_is_not_flagged(): void
    {
        $actor = $this->user('Admin Ada');
        $subject = $this->user('Subject Sid');

        $this->accessChange($actor, $subject, ['analyst'], ['analyst', 'manager']);
        $this->scan();

        $this->assertNull($this->finding('privilege_escalation'));
    }

    /* ------------------------------------------------------------------
     | Data exfiltration
     |------------------------------------------------------------------ */

    public function test_it_flags_bulk_report_export(): void
    {
        Config::set('security.detection.data_exfiltration.exports_per_user', 5);
        $user = $this->user('Bulk Bob');

        for ($i = 0; $i < 5; $i++) {
            $this->audit('report.exported', $user, ['ip_address' => '203.0.113.20']);
        }

        $this->scan();

        $finding = $this->finding('data_exfiltration');
        $this->assertNotNull($finding);
        $this->assertSame(5, $finding->evidence['export_count']);
    }

    public function test_export_volume_below_the_threshold_is_not_flagged(): void
    {
        Config::set('security.detection.data_exfiltration.exports_per_user', 5);
        $user = $this->user('Casual Cass');

        for ($i = 0; $i < 4; $i++) {
            $this->audit('report.exported', $user, ['ip_address' => '203.0.113.21']);
        }

        $this->scan();

        $this->assertNull($this->finding('data_exfiltration'));
    }

    /* ------------------------------------------------------------------
     | Dormant account
     |------------------------------------------------------------------ */

    public function test_it_flags_a_sign_in_after_a_long_absence(): void
    {
        Config::set('security.detection.dormant_account.dormant_days', 90);
        $user = $this->user('Returning Rae');

        $this->audit('auth.login', $user, ['created_at' => now()->subDays(200)]);
        $this->audit('auth.login', $user, ['ip_address' => '203.0.113.30']);

        $this->scan();

        $finding = $this->finding('dormant_account');
        $this->assertNotNull($finding);
        $this->assertGreaterThanOrEqual(90, $finding->evidence['days_dormant']);
    }

    public function test_a_regular_user_is_not_treated_as_dormant(): void
    {
        Config::set('security.detection.dormant_account.dormant_days', 90);
        $user = $this->user('Frequent Fran');

        $this->audit('auth.login', $user, ['created_at' => now()->subDays(2)]);
        $this->audit('auth.login', $user, ['ip_address' => '203.0.113.31']);

        $this->scan();

        $this->assertNull($this->finding('dormant_account'));
    }

    /* ------------------------------------------------------------------
     | After-hours administrative activity
     |------------------------------------------------------------------ */

    public function test_it_flags_an_access_change_made_outside_business_hours(): void
    {
        Config::set('security.detection.after_hours.enabled', true);
        Config::set('security.detection.after_hours.start_hour', 6);
        Config::set('security.detection.after_hours.end_hour', 21);

        $actor = $this->user('Night Owl');
        // A Wednesday at 03:00 local time.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02 03:30:00', config('app.timezone')));
        $this->audit('user.access.updated', $actor, ['created_at' => now()]);

        $this->scan();

        $this->assertNotNull($this->finding('after_hours_admin'));
        CarbonImmutable::setTestNow();
    }

    public function test_an_access_change_older_than_the_window_is_not_flagged(): void
    {
        /*
         * Regression: the event alternatives were not grouped, so SQL bound the
         * AND conditions to the second branch only. Every `user.access.updated`
         * row ever recorded escaped the scan window and was re-evaluated on
         * every scan, keeping stale findings permanently fresh.
         */
        Config::set('security.detection.after_hours.enabled', true);
        Config::set('security.detection.window_minutes', 60);

        $actor = $this->user('Night Owl');
        $this->audit('user.access.updated', $actor, [
            'created_at' => CarbonImmutable::parse('2020-01-05 03:30:00'),
        ]);

        $this->scan();

        $this->assertNull(
            $this->finding('after_hours_admin'),
            'An access change from years ago is outside the scan window.'
        );
    }

    /* ------------------------------------------------------------------
     | Revoked session probing
     |------------------------------------------------------------------ */

    public function test_it_flags_repeated_use_of_a_revoked_session(): void
    {
        $user = $this->user('Revoked Rex');

        for ($i = 0; $i < 3; $i++) {
            $this->audit('auth.session_revoked', $user, ['ip_address' => '203.0.113.40']);
        }

        $this->scan();

        $this->assertNotNull($this->finding('inactive_session_probe'));
    }

    public function test_an_isolated_revoked_session_is_not_flagged(): void
    {
        $user = $this->user('One Off Ollie');
        $this->audit('auth.session_revoked', $user, ['ip_address' => '203.0.113.41']);

        $this->scan();

        $this->assertNull($this->finding('inactive_session_probe'));
    }

    /* ------------------------------------------------------------------
     | Two-factor gaps
     |------------------------------------------------------------------ */

    public function test_it_flags_a_privileged_account_without_a_second_factor(): void
    {
        $admin = $this->user('Unenrolled Ana');
        $admin->roles()->attach($this->role('administrator'));

        $this->scan();

        $finding = $this->finding('two_factor_gap');
        $this->assertNotNull($finding);
        $this->assertSame('high', $finding->severity);
        $this->assertSame('Unenrolled Ana', $finding->evidence['user']);
    }

    public function test_an_enrolled_privileged_account_is_not_flagged(): void
    {
        $admin = $this->user('Enrolled Eve');
        $admin->roles()->attach($this->role('administrator'));
        // Two-factor columns are deliberately not mass assignable, so `update`
        // would drop them silently and the account would still look unenrolled.
        $admin->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_secret' => 'seed',
        ])->save();

        $this->scan();

        $this->assertNull($this->finding('two_factor_gap'));
    }

    public function test_disabling_multi_factor_entirely_is_reported_as_critical(): void
    {
        Config::set('security.two_factor.enabled', false);

        $this->scan();

        $finding = $this->finding('two_factor_gap');
        $this->assertNotNull($finding);
        $this->assertSame('critical', $finding->severity);
    }

    /* ------------------------------------------------------------------
     | Helpers
     |------------------------------------------------------------------ */

    private function scan(): void
    {
        app(SecurityMonitor::class)->scan('test');
    }

    private function finding(string $detector): ?SecurityEvent
    {
        return SecurityEvent::where('detector', $detector)->first();
    }

    private function user(string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'department' => 'Information Technology',
            'is_active' => true,
        ]);
    }

    private function role(string $name): Role
    {
        $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name)]);
        $permission = Permission::firstOrCreate(
            ['name' => 'security.view'],
            ['label' => 'security.view', 'group' => 'Security'],
        );

        if (! $role->permissions()->where('permissions.id', $permission->id)->exists()) {
            $role->permissions()->attach($permission);
        }

        return $role;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function audit(string $event, User $user, array $attributes = []): AuditLog
    {
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $log = AuditLog::create([
            'user_id' => $user->id,
            'event' => $event,
            'user_agent' => 'PHPUnit',
            ...$attributes,
        ]);

        if ($createdAt) {
            DB::table('audit_logs')->where('id', $log->id)->update(['created_at' => $createdAt]);
        }

        return $log->fresh();
    }

    /**
     * @param  list<string>  $rolesBefore
     * @param  list<string>  $rolesAfter
     */
    private function accessChange(
        User $actor,
        User $subject,
        array $rolesBefore,
        array $rolesAfter,
        bool $activeBefore = true,
        bool $activeAfter = true,
    ): void {
        AuditLog::create([
            'user_id' => $actor->id,
            'event' => 'user.access.updated',
            'auditable_type' => User::class,
            'auditable_id' => (string) $subject->id,
            'ip_address' => '203.0.113.50',
            'user_agent' => 'PHPUnit',
            'metadata' => [
                'before' => ['is_active' => $activeBefore, 'roles' => $rolesBefore],
                'after' => ['is_active' => $activeAfter, 'roles' => $rolesAfter],
            ],
        ]);
    }
}
