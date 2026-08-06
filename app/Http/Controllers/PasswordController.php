<?php

namespace App\Http\Controllers;

use App\Rules\CompliantPassword;
use App\Services\Security\PasswordPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Self-service password change under the NIST 800-63B policy.
 */
class PasswordController extends Controller
{
    public function __construct(private readonly PasswordPolicyService $passwords) {}

    /**
     * The active policy, so the UI can state the rules before submission.
     */
    public function policy(Request $request): JsonResponse
    {
        $config = config('security.password');
        $user = $request->user();

        return response()->json([
            'min_length' => $config['min_length'],
            'history_depth' => $config['history_depth'],
            'blocks_compromised' => $config['block_compromised'],
            'rotation_enabled' => $config['max_age_days'] > 0,
            'max_age_days' => $config['max_age_days'] > 0 ? $config['max_age_days'] : null,
            'must_change' => $this->passwords->mustChange($user),
            'password_age_days' => $user->passwordAgeInDays(),
            'guidance' => [
                "Use at least {$config['min_length']} characters.",
                'A memorable passphrase of several words beats a short complex string.',
                'Do not reuse a password from another service.',
                "You cannot reuse your last {$config['history_depth']} passwords.",
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            // Rules run against this user so history and contextual checks apply.
            'password' => ['required', 'string', 'confirmed', new CompliantPassword($user)],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That password is not correct.',
            ]);
        }

        $this->passwords->update($user, $validated['password'], 'self_service');

        // A password change invalidates other sessions: if the change was
        // prompted by suspected compromise, the attacker's session must die.
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Your password has been changed.',
        ]);
    }
}
