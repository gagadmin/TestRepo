<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Security\LoginThrottleService;
use App\Services\Security\PasswordPolicyService;
use App\Services\Security\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Two-step authentication.
 *
 *   POST /auth/login              password -> pending challenge (no session)
 *   POST /auth/two-factor         TOTP or recovery code -> authenticated session
 *
 * The central invariant, covered by test
 * `test_no_authenticated_session_exists_before_the_second_factor`:
 * for an enrolled account, a valid password alone establishes NO authenticated
 * session. Until the second factor is verified the only server state is a short
 * lived, unprivileged pending record in the session.
 */
class AuthController extends Controller
{
    /** Session key holding the half-completed login. */
    private const PENDING_KEY = 'auth.two_factor_pending';

    public function __construct(
        private readonly LoginThrottleService $throttle,
        private readonly TwoFactorService $twoFactor,
        private readonly PasswordPolicyService $passwords,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);

        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:4096'],
        ]);

        $email = $credentials['email'];
        $ip = (string) $request->ip();

        // Lockout is checked before any credential work so a locked account
        // costs an attacker a request without revealing anything.
        if ($seconds = $this->throttle->lockedFor($email, $ip)) {
            return $this->lockedResponse($seconds);
        }

        // Auth::validate rather than Auth::attempt: this verifies the password
        // WITHOUT logging anyone in. The session is established only after the
        // second factor, or immediately below for accounts with none.
        if (! Auth::validate([...$credentials, 'is_active' => true])) {
            $lockedFor = $this->throttle->recordFailure($email, $ip);

            AuditLog::create([
                'event' => 'auth.login_failed',
                'auditable_type' => 'user',
                'ip_address' => $ip,
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'email_fingerprint' => $this->throttle->identifier($email),
                    'locked' => $lockedFor > 0,
                ],
            ]);

            if ($lockedFor > 0) {
                return $this->lockedResponse($lockedFor);
            }

            // Deliberately identical for a wrong password, an unknown address,
            // and a deactivated account: no user enumeration.
            throw ValidationException::withMessages([
                'email' => 'The supplied credentials are not valid.',
            ]);
        }

        $user = User::where('email', $email)->where('is_active', true)->firstOrFail();

        $this->throttle->clear($email, $ip, LoginThrottleService::STAGE_PASSWORD);

        // Enrolled: hand back a challenge and stop. No session yet.
        if ($user->hasConfirmedTwoFactor()) {
            $request->session()->put(self::PENDING_KEY, [
                'user_id' => $user->id,
                'remember' => $request->boolean('remember'),
                'expires_at' => now()
                    ->addMinutes((int) config('security.two_factor.challenge_ttl_minutes', 5))
                    ->timestamp,
            ]);

            AuditLog::create([
                'user_id' => $user->id,
                'event' => 'auth.two_factor.challenged',
                'auditable_type' => $user::class,
                'auditable_id' => (string) $user->id,
                'ip_address' => $ip,
                'user_agent' => $request->userAgent(),
                'metadata' => [],
            ]);

            return response()->json([
                'two_factor_required' => true,
                'recovery_codes_available' => $this->twoFactor->remainingRecoveryCodes($user) > 0,
                'message' => 'Enter the six-digit code from your authenticator app.',
            ]);
        }

        // Not enrolled. Sign in, but the EnsureTwoFactorEnrolled middleware
        // confines the session to the enrolment endpoints when policy requires
        // a second factor.
        $this->establishSession($request, $user, $request->boolean('remember'));

        return response()->json([
            'two_factor_required' => false,
            'two_factor_setup_required' => $user->requiresTwoFactor(),
            'must_change_password' => $this->passwords->mustChange($user),
            'message' => 'Signed in successfully.',
        ]);
    }

    /**
     * Verify the second factor and establish the session.
     */
    public function twoFactorChallenge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $pending = $request->session()->get(self::PENDING_KEY);

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < now()->timestamp) {
            $request->session()->forget(self::PENDING_KEY);

            return response()->json([
                'message' => 'That sign-in attempt expired. Enter your password again.',
            ], 410);
        }

        $user = User::where('id', $pending['user_id'])->where('is_active', true)->first();

        if (! $user || ! $user->hasConfirmedTwoFactor()) {
            $request->session()->forget(self::PENDING_KEY);

            return response()->json(['message' => 'That sign-in attempt is no longer valid.'], 410);
        }

        $ip = (string) $request->ip();

        if ($seconds = $this->throttle->lockedFor($user->email, $ip, LoginThrottleService::STAGE_TWO_FACTOR)) {
            return $this->lockedResponse($seconds);
        }

        if (! $this->twoFactor->verifyChallenge($user, $validated['code'])) {
            $lockedFor = $this->throttle->recordFailure(
                $user->email,
                $ip,
                LoginThrottleService::STAGE_TWO_FACTOR,
            );

            AuditLog::create([
                'user_id' => $user->id,
                'event' => 'auth.two_factor.failed',
                'auditable_type' => $user::class,
                'auditable_id' => (string) $user->id,
                'ip_address' => $ip,
                'user_agent' => $request->userAgent(),
                'metadata' => ['locked' => $lockedFor > 0],
            ]);

            if ($lockedFor > 0) {
                // Force a restart from the password step.
                $request->session()->forget(self::PENDING_KEY);

                return $this->lockedResponse($lockedFor);
            }

            throw ValidationException::withMessages([
                'code' => 'That code is not valid or has already been used.',
            ]);
        }

        $request->session()->forget(self::PENDING_KEY);
        $this->throttle->clear($user->email, $ip);

        $this->establishSession($request, $user, (bool) ($pending['remember'] ?? false));

        return response()->json([
            'must_change_password' => $this->passwords->mustChange($user),
            'recovery_codes_remaining' => $this->twoFactor->remainingRecoveryCodes($user),
            'message' => 'Signed in successfully.',
        ]);
    }

    /**
     * Abandon a pending challenge (the user pressed "start again").
     */
    public function cancelTwoFactorChallenge(Request $request): JsonResponse
    {
        $request->session()->forget(self::PENDING_KEY);

        return response()->json(['message' => 'Sign-in cancelled.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        AuditLog::create([
            'user_id' => $user?->id,
            'event' => 'auth.logout',
            'auditable_type' => 'user',
            'auditable_id' => $user ? (string) $user->id : null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [],
        ]);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out successfully.']);
    }

    /**
     * Log in, rotate the session id, and record the event.
     */
    private function establishSession(Request $request, User $user, bool $remember): void
    {
        Auth::login($user, $remember);

        // Session fixation defence: a new id for the newly privileged session.
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        AuditLog::create([
            'user_id' => $user->id,
            'event' => 'auth.login',
            'auditable_type' => $user::class,
            'auditable_id' => (string) $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                'two_factor' => $user->hasConfirmedTwoFactor(),
            ],
        ]);
    }

    private function lockedResponse(int $seconds): JsonResponse
    {
        $minutes = (int) ceil($seconds / 60);

        // 423 Locked distinguishes a lockout from a 429 rate limit, so the
        // client can present a specific message.
        return response()->json([
            'message' => $minutes <= 1
                ? 'Too many failed attempts. Try again in about a minute.'
                : "Too many failed attempts. Try again in about {$minutes} minutes.",
            'locked' => true,
            'retry_after_seconds' => $seconds,
        ], 423);
    }
}
