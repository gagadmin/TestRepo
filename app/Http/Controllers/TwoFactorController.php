<?php

namespace App\Http\Controllers;

use App\Services\Security\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Second-factor enrolment and management for the signed-in user.
 *
 * These routes are reachable while a mandatory-enrolment session is otherwise
 * confined, which is what lets a user complete setup.
 */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    /**
     * Current enrolment state, used to drive the UI.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'enabled' => $user->hasConfirmedTwoFactor(),
            'required' => $user->requiresTwoFactor(),
            'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
            'recovery_codes_remaining' => $this->twoFactor->remainingRecoveryCodes($user),
        ]);
    }

    /**
     * Generate a secret and return the QR code.
     */
    public function setup(Request $request): JsonResponse
    {
        try {
            $enrolment = $this->twoFactor->beginEnrolment($request->user());
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'qr_code_svg' => $enrolment['qr_code_svg'],
            // Shown so a user whose camera cannot scan can type it in.
            'secret' => $enrolment['secret'],
            'otpauth_uri' => $enrolment['otpauth_uri'],
            'message' => 'Scan the code, then enter the six-digit code to confirm.',
        ]);
    }

    /**
     * Confirm enrolment and reveal the recovery codes once.
     */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        try {
            $codes = $this->twoFactor->confirmEnrolment($request->user(), $validated['code']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        return response()->json([
            'recovery_codes' => $codes,
            'message' => 'Two-factor authentication is on. Save these recovery codes now — they are not shown again.',
        ]);
    }

    /**
     * Issue a new set of recovery codes. Re-authentication required.
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $this->confirmPassword($request);

        try {
            $codes = $this->twoFactor->regenerateRecoveryCodes($request->user());
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'recovery_codes' => $codes,
            'message' => 'New recovery codes issued. The previous set no longer works.',
        ]);
    }

    /**
     * Turn off the second factor. Refused when policy requires it.
     */
    public function disable(Request $request): JsonResponse
    {
        $this->confirmPassword($request);

        try {
            $this->twoFactor->disable($request->user(), $request->user());
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        return response()->json(['message' => 'Two-factor authentication has been turned off.']);
    }

    /**
     * Require the current password before a sensitive change.
     *
     * Without this, anyone with a hijacked session could rotate the recovery
     * codes or remove the second factor outright.
     */
    private function confirmPassword(Request $request): void
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        if (! Hash::check($validated['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That password is not correct.',
            ]);
        }
    }
}
