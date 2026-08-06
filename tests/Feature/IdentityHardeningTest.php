<?php

namespace Tests\Feature;

use App\Models\LoginThrottle;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Security\LoginThrottleService;
use App\Services\Security\PasswordPolicyService;
use App\Services\Security\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class IdentityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const GOOD_PASSWORD = 'harbour-lantern-quiet-47';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('security.two_factor.enabled', true);
        Config::set('security.two_factor.required_for_all', true);
        Config::set('security.lockout.enabled', true);
        Config::set('security.lockout.threshold', 3);
        Config::set('security.lockout.backoff_minutes', [1, 5, 15]);
    }

    /* ==============================================================
     * ISO 27001 A.8.5 / NIST IA-2 — multi-factor authentication
     * ============================================================== */

    /**
     * The central invariant of the whole feature.
     */
    public function test_no_authenticated_session_exists_before_the_second_factor(): void
    {
        $user = $this->enrolledUser();

        $response = $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => self::GOOD_PASSWORD,
        ]);

        $response->assertOk()->assertJson(['two_factor_required' => true]);

        // A correct password alone must not authenticate anyone.
        $this->assertFalse(Auth::check(), 'A session was established before the second factor.');
        $this->assertGuest();

        // And the session must not be usable against a protected endpoint.
        $this->getJson('/api/bootstrap')->assertUnauthorized();
    }

    public function test_valid_totp_code_completes_sign_in(): void
    {
        $user = $this->enrolledUser();

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => self::GOOD_PASSWORD,
        ])->assertOk();

        $this->postJson('/auth/two-factor', ['code' => $this->currentCode($user)])
            ->assertOk()
            ->assertJsonPath('message', 'Signed in successfully.');

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_a_totp_code_cannot_be_replayed(): void
    {
        $user = $this->enrolledUser();
        $code = $this->currentCode($user);

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => self::GOOD_PASSWORD,
        ]);
        $this->postJson('/auth/two-factor', ['code' => $code])->assertOk();

        $this->postJson('/auth/logout')->assertOk();

        // Same code, same 30-second window, second attempt.
        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => self::GOOD_PASSWORD,
        ]);
        $this->postJson('/auth/two-factor', ['code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertGuest();
    }

    public function test_wrong_code_does_not_authenticate(): void
    {
        $user = $this->enrolledUser();

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => self::GOOD_PASSWORD,
        ]);

        $this->postJson('/auth/two-factor', ['code' => '000000'])
            ->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_challenge_expires(): void
    {
        $user = $this->enrolledUser();

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => self::GOOD_PASSWORD,
        ]);

        $this->travel(6)->minutes();

        $this->postJson('/auth/two-factor', ['code' => $this->currentCode($user)])
            ->assertStatus(410);

        $this->assertGuest();
    }

    public function test_a_recovery_code_works_once_only(): void
    {
        $user = $this->userWithPassword();
        $service = app(TwoFactorService::class);
        $service->beginEnrolment($user);
        $user->refresh();
        $codes = $service->confirmEnrolment($user, $this->currentCode($user));

        $this->assertCount(8, $codes);

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => self::GOOD_PASSWORD,
        ]);
        $this->postJson('/auth/two-factor', ['code' => $codes[0]])->assertOk();
        $this->assertAuthenticated();

        $this->postJson('/auth/logout');

        // The same recovery code must now be spent.
        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => self::GOOD_PASSWORD,
        ]);
        $this->postJson('/auth/two-factor', ['code' => $codes[0]])
            ->assertUnprocessable();

        $this->assertGuest();
        $this->assertSame(7, app(TwoFactorService::class)->remainingRecoveryCodes($user->fresh()));
    }

    public function test_recovery_codes_are_stored_hashed(): void
    {
        $user = $this->userWithPassword();
        $service = app(TwoFactorService::class);
        $service->beginEnrolment($user);
        $user->refresh();
        $codes = $service->confirmEnrolment($user, $this->currentCode($user));

        $stored = $user->fresh()->two_factor_recovery_codes;

        // A database reader must not be able to use them.
        $this->assertNotContains($codes[0], $stored);
        $this->assertTrue(Hash::check($codes[0], $stored[0]));
    }

    public function test_unenrolled_session_is_confined_to_the_enrolment_flow(): void
    {
        $user = $this->userWithPassword();

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => self::GOOD_PASSWORD,
        ])->assertOk()->assertJson(['two_factor_setup_required' => true]);

        $this->assertAuthenticated();

        // Business data is unreachable.
        $this->getJson('/api/reports')
            ->assertForbidden()
            ->assertJsonPath('code', 'two_factor_setup_required');

        // The enrolment flow itself is reachable.
        $this->getJson('/api/two-factor')->assertOk();
        $this->postJson('/api/two-factor/setup')->assertOk()
            ->assertJsonStructure(['qr_code_svg', 'secret', 'otpauth_uri']);
    }

    public function test_a_user_cannot_remove_a_mandatory_second_factor(): void
    {
        $user = $this->enrolledUser();
        $this->actingAs($user);

        $this->deleteJson('/api/two-factor', ['current_password' => self::GOOD_PASSWORD])
            ->assertForbidden();

        $this->assertTrue($user->fresh()->hasConfirmedTwoFactor());
    }

    public function test_two_factor_secret_is_never_serialised(): void
    {
        $user = $this->enrolledUser();

        $array = $user->toArray();

        $this->assertArrayNotHasKey('two_factor_secret', $array);
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $array);
        $this->assertArrayNotHasKey('password', $array);
    }

    public function test_secret_is_encrypted_at_rest(): void
    {
        $user = $this->enrolledUser();

        $raw = \DB::table('users')->where('id', $user->id)->value('two_factor_secret');

        $this->assertNotSame($user->two_factor_secret, $raw);
        $this->assertStringNotContainsString($user->two_factor_secret, (string) $raw);
    }

    /* ==============================================================
     * CIS 6.2 — account lockout
     * ============================================================== */

    public function test_lockout_engages_after_the_threshold_with_progressive_backoff(): void
    {
        $user = $this->enrolledUser();
        $throttles = app(LoginThrottleService::class);

        // Threshold is 3; the first two failures must not lock.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $this->postJson('/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password-here',
            ])->assertUnprocessable();
        }

        $this->assertFalse($throttles->isLocked($user->email, '127.0.0.1'));

        // Third failure locks for the first backoff step.
        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password-here',
        ])->assertStatus(423)->assertJson(['locked' => true]);

        $this->assertTrue($throttles->isLocked($user->email, '127.0.0.1'));

        // A correct password is refused while locked.
        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => self::GOOD_PASSWORD,
        ])->assertStatus(423);

        // Backoff grows: step 2 is 5 minutes, so 1 minute is not enough.
        $this->travel(2)->minutes();
        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password-here',
        ])->assertStatus(423);

        $throttle = LoginThrottle::where('ip_address', '127.0.0.1')
            ->where('stage', 'password')
            ->firstOrFail();
        $this->assertSame(4, $throttle->failure_count);
    }

    public function test_lockout_is_scoped_to_the_source_address(): void
    {
        $user = $this->enrolledUser();

        // Lock out one address.
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
                ->postJson('/auth/login', [
                    'email' => $user->email,
                    'password' => 'wrong-password-here',
                ]);
        }

        $throttles = app(LoginThrottleService::class);
        $this->assertTrue($throttles->isLocked($user->email, '203.0.113.10'));

        // The real owner at a different address is unaffected. This is the
        // denial-of-service protection: an attacker cannot lock a user out.
        $this->assertFalse($throttles->isLocked($user->email, '198.51.100.4'));
    }

    public function test_successful_sign_in_clears_the_counter(): void
    {
        $user = $this->enrolledUser();
        $throttles = app(LoginThrottleService::class);

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password-here',
        ]);

        $this->assertSame(2, $throttles->remainingAttempts($user->email, '127.0.0.1'));

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => self::GOOD_PASSWORD,
        ])->assertOk();

        $this->assertSame(3, $throttles->remainingAttempts($user->email, '127.0.0.1'));
    }

    public function test_second_factor_attempts_have_a_separate_budget(): void
    {
        Config::set('security.lockout.two_factor_threshold', 2);
        $user = $this->enrolledUser();
        $throttles = app(LoginThrottleService::class);

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => self::GOOD_PASSWORD,
        ]);

        $this->postJson('/auth/two-factor', ['code' => '111111'])->assertUnprocessable();
        $this->postJson('/auth/two-factor', ['code' => '222222'])->assertStatus(423);

        // The password budget is untouched by wrong codes.
        $this->assertFalse($throttles->isLocked($user->email, '127.0.0.1', 'password'));
        $this->assertTrue($throttles->isLocked($user->email, '127.0.0.1', 'two_factor'));
    }

    public function test_administrator_can_clear_a_lockout(): void
    {
        $user = $this->enrolledUser();
        $admin = $this->administrator();
        $twoFactor = app(TwoFactorService::class);
        $twoFactor->beginEnrolment($admin);
        $admin->refresh();
        $twoFactor->confirmEnrolment($admin, $this->currentCode($admin));
        $throttles = app(LoginThrottleService::class);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $throttles->recordFailure($user->email, '203.0.113.10');
        }
        $this->assertTrue($throttles->isLocked($user->email, '203.0.113.10'));

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$user->id}/unlock")
            ->assertOk();

        $this->assertFalse($throttles->isLocked($user->email, '203.0.113.10'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.unlocked']);
    }

    public function test_lockout_does_not_reveal_whether_an_account_exists(): void
    {
        // Same status and message shape for a real and an unknown address.
        $user = $this->enrolledUser();

        $real = $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password-here',
        ]);
        $unknown = $this->postJson('/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password-here',
        ]);

        $this->assertSame($real->status(), $unknown->status());
        $this->assertSame(
            $real->json('errors.email'),
            $unknown->json('errors.email'),
        );
    }

    /* ==============================================================
     * NIST IA-5 / SP 800-63B — password policy
     * ============================================================== */

    public function test_short_passwords_are_rejected(): void
    {
        $failures = app(PasswordPolicyService::class)->validate('short-one');

        $this->assertNotEmpty($failures);
        $this->assertStringContainsString('at least 12 characters', implode(' ', $failures));
    }

    public function test_compromised_passwords_are_rejected(): void
    {
        $policy = app(PasswordPolicyService::class);

        // Present in resources/security/compromised-passwords.txt.
        $failures = $policy->validate('correcthorsebatterystaple');

        $this->assertNotEmpty($failures);
        $this->assertStringContainsString('breach', implode(' ', $failures));
    }

    public function test_low_entropy_passwords_are_rejected_despite_length(): void
    {
        $policy = app(PasswordPolicyService::class);

        foreach (['aaaaaaaaaaaaaa', 'abcdefghijklmn', '123456789012345'] as $candidate) {
            $this->assertNotEmpty(
                $policy->validate($candidate),
                "[{$candidate}] should have been rejected.",
            );
        }
    }

    public function test_passwords_containing_the_users_identity_are_rejected(): void
    {
        $user = User::factory()->create([
            'name' => 'Jacob Calit',
            'email' => 'jacob.calit@example.com',
        ]);

        $failures = app(PasswordPolicyService::class)->validate('jacob-summer-river', $user);

        $this->assertStringContainsString('name or email', implode(' ', $failures));
    }

    public function test_a_strong_passphrase_is_accepted(): void
    {
        $this->assertTrue(app(PasswordPolicyService::class)->isValid('harbour-lantern-quiet-47'));
    }

    public function test_recent_passwords_cannot_be_reused(): void
    {
        Config::set('security.password.history_depth', 3);
        $user = $this->userWithPassword();
        $policy = app(PasswordPolicyService::class);

        $policy->update($user, 'first-rotation-phrase-1');
        $policy->update($user, 'second-rotation-phrase-2');

        // The original and both rotations are now blocked.
        foreach ([self::GOOD_PASSWORD, 'first-rotation-phrase-1', 'second-rotation-phrase-2'] as $old) {
            $failures = $policy->validate($old, $user->fresh());
            $this->assertNotEmpty($failures, "[{$old}] should be blocked as reuse.");
        }

        $this->assertTrue($policy->isValid('a-genuinely-new-phrase-9', $user->fresh()));
    }

    public function test_history_is_pruned_to_the_configured_depth(): void
    {
        Config::set('security.password.history_depth', 2);
        $user = $this->userWithPassword();
        $policy = app(PasswordPolicyService::class);

        foreach (['rotation-one-phrase-11', 'rotation-two-phrase-22', 'rotation-three-phrase-33'] as $new) {
            $policy->update($user, $new);
            $user->refresh();
        }

        $this->assertLessThanOrEqual(2, $user->passwordHistories()->count());
    }

    public function test_periodic_rotation_is_disabled_by_default(): void
    {
        // NIST SP 800-63B 5.1.1.2 advises against arbitrary periodic change.
        $this->assertSame(0, (int) config('security.password.max_age_days'));

        $user = $this->userWithPassword();
        $user->forceFill(['password_changed_at' => now()->subYears(3)])->save();

        $this->assertFalse(app(PasswordPolicyService::class)->mustChange($user));
    }

    public function test_rotation_can_be_enabled_by_configuration(): void
    {
        Config::set('security.password.max_age_days', 90);
        $user = $this->userWithPassword();
        $user->forceFill(['password_changed_at' => now()->subDays(120)])->save();

        $this->assertTrue(app(PasswordPolicyService::class)->mustChange($user));
    }

    public function test_a_forced_password_change_confines_the_session(): void
    {
        $user = $this->enrolledUser();
        app(PasswordPolicyService::class)->requireChange($user, 'suspected_compromise');

        $this->actingAs($user->fresh());

        $this->getJson('/api/reports')
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');

        // The change endpoint stays reachable.
        $this->getJson('/api/account/password/policy')->assertOk();
    }

    public function test_changing_the_password_requires_the_current_one(): void
    {
        $user = $this->enrolledUser();

        $this->actingAs($user)
            ->putJson('/api/account/password', [
                'current_password' => 'not-the-right-password',
                'password' => 'brand-new-phrase-42',
                'password_confirmation' => 'brand-new-phrase-42',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');
    }

    public function test_a_successful_change_clears_the_forced_flag(): void
    {
        $user = $this->enrolledUser();
        app(PasswordPolicyService::class)->requireChange($user, 'test');

        $this->actingAs($user->fresh())
            ->putJson('/api/account/password', [
                'current_password' => self::GOOD_PASSWORD,
                'password' => 'brand-new-phrase-42',
                'password_confirmation' => 'brand-new-phrase-42',
            ])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertFalse($fresh->must_change_password);
        $this->assertTrue(Hash::check('brand-new-phrase-42', $fresh->password));
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.password.changed']);
    }

    public function test_the_plaintext_password_is_never_audited(): void
    {
        $user = $this->enrolledUser();

        $this->actingAs($user)->putJson('/api/account/password', [
            'current_password' => self::GOOD_PASSWORD,
            'password' => 'brand-new-phrase-42',
            'password_confirmation' => 'brand-new-phrase-42',
        ]);

        $logs = \DB::table('audit_logs')->pluck('metadata')->implode(' ');

        $this->assertStringNotContainsString('brand-new-phrase-42', $logs);
        $this->assertStringNotContainsString(self::GOOD_PASSWORD, $logs);
    }

    /* ==============================================================
     * Compliance reporting
     * ============================================================== */

    public function test_the_three_controls_now_report_as_passing(): void
    {
        // One privileged account, enrolled, so the MFA control can pass.
        $admin = $this->administrator();
        $service = app(TwoFactorService::class);
        $service->beginEnrolment($admin);
        $admin->refresh();
        $service->confirmEnrolment($admin, $this->currentCode($admin));

        $compliance = app(\App\Services\Security\SecurityPostureService::class)->compliance();
        $byId = collect($compliance['controls'])->keyBy('id');

        $this->assertTrue($byId['mfa']['passed'], $byId['mfa']['detail']);
        $this->assertTrue($byId['password_policy']['passed'], $byId['password_policy']['detail']);
        $this->assertTrue($byId['account_lockout']['passed'], $byId['account_lockout']['detail']);
    }

    public function test_mfa_control_fails_when_a_privileged_account_is_unenrolled(): void
    {
        $this->administrator(); // created without enrolling

        $compliance = app(\App\Services\Security\SecurityPostureService::class)->compliance();
        $mfa = collect($compliance['controls'])->firstWhere('id', 'mfa');

        $this->assertFalse($mfa['passed']);
        $this->assertStringContainsString('not enrolled', $mfa['detail']);
    }

    public function test_mfa_coverage_is_measured_not_assumed(): void
    {
        $identity = app(\App\Services\Security\SecurityPostureService::class)->identityAccess();

        $this->assertTrue($identity['mfa']['supported']);
        $this->assertTrue($identity['mfa']['enabled']);
        $this->assertIsFloat($identity['mfa']['coverage_percentage']);
    }

    /* ==============================================================
     * Helpers
     * ============================================================== */

    private function userWithPassword(): User
    {
        return User::factory()->create([
            'password' => self::GOOD_PASSWORD,
            'is_active' => true,
            'password_changed_at' => now(),
        ]);
    }

    private function enrolledUser(): User
    {
        $user = $this->userWithPassword();
        $service = app(TwoFactorService::class);
        $service->beginEnrolment($user);
        $user->refresh();
        $service->confirmEnrolment($user, $this->currentCode($user));

        // Clear the timestep so the next test-generated code is accepted.
        $user->forceFill(['two_factor_last_used_timestep' => null])->save();

        return $user->fresh();
    }

    private function administrator(): User
    {
        $role = Role::firstOrCreate(['name' => 'administrator'], ['label' => 'Administrator']);

        foreach (['users.view', 'users.manage'] as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'group' => 'Administration'],
            );

            if (! $role->permissions()->where('permissions.id', $permission->id)->exists()) {
                $role->permissions()->attach($permission);
            }
        }

        $user = $this->userWithPassword();
        $user->roles()->attach($role);

        return $user->fresh();
    }

    private function currentCode(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);
    }
}
