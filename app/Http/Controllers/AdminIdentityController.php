<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Security\LoginThrottleService;
use App\Services\Security\PasswordPolicyService;
use App\Services\Security\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Administrative recovery actions for the identity controls.
 *
 * These exist because the controls are strict: a user who loses their
 * authenticator and their recovery codes, or who is locked out by an attacker
 * hammering their account from another address, needs a way back in that does
 * not involve disabling the control globally.
 *
 * Every action here is privileged, audited, and cannot be performed on oneself
 * where that would defeat the control.
 */
class AdminIdentityController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly LoginThrottleService $throttles,
        private readonly PasswordPolicyService $passwords,
    ) {}

    /**
     * Clear every lockout for an account, across all source addresses.
     */
    public function unlock(Request $request, int $user): JsonResponse
    {
        $target = User::findOrFail($user);
        $cleared = $this->throttles->unlockAccount($target->email);

        $this->audit($request, 'user.unlocked', $target, ['rows_cleared' => $cleared]);

        return response()->json([
            'message' => "Lockouts cleared for {$target->name}.",
            'rows_cleared' => $cleared,
        ]);
    }

    /**
     * Reset a user's second factor so they can enrol a new device.
     *
     * Self-reset is refused: an attacker holding a hijacked admin session would
     * otherwise be able to strip their own second factor and persist.
     */
    public function resetTwoFactor(Request $request, int $user): JsonResponse
    {
        $target = User::findOrFail($user);

        if ($target->id === $request->user()->id) {
            return response()->json([
                'message' => 'Use the two-factor settings on your own account to change your device.',
            ], 422);
        }

        try {
            // The actor is passed so the service permits removal of a factor
            // that policy would otherwise make mandatory.
            $this->twoFactor->disable($target, $request->user());
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        $this->audit($request, 'user.two_factor_reset', $target);

        return response()->json([
            'message' => "{$target->name} must enrol a new authenticator at next sign-in.",
        ]);
    }

    /**
     * Force a password change at next sign-in.
     */
    public function requirePasswordChange(Request $request, int $user): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $target = User::findOrFail($user);
        $this->passwords->requireChange($target, $validated['reason']);

        $this->audit($request, 'user.password_change_required', $target, [
            'reason' => $validated['reason'],
        ]);

        return response()->json([
            'message' => "{$target->name} must change their password at next sign-in.",
        ]);
    }

    private function audit(Request $request, string $event, User $target, array $metadata = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'event' => $event,
            'auditable_type' => User::class,
            'auditable_id' => (string) $target->id,
            'ip_address' => $request->ip(),
            'user_agent' => str($request->userAgent() ?? '')->limit(500)->toString(),
            'metadata' => [
                'target_user' => $target->name,
                ...$metadata,
            ],
        ]);
    }
}
