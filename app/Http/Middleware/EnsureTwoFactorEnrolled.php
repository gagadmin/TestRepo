<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confines a session to the enrolment endpoints until the account holds a
 * second factor (ISO 27001 A.8.5 / NIST IA-2).
 *
 * Mandatory MFA is only meaningful if an unenrolled session cannot reach
 * business data. Without this, a user could sign in with a password, ignore
 * the enrolment prompt, and keep working indefinitely.
 */
class EnsureTwoFactorEnrolled
{
    /**
     * Routes reachable while enrolment is outstanding: the enrolment flow
     * itself, the bootstrap payload the SPA needs to render, and sign-out.
     */
    private const ALLOWED_ROUTES = [
        'logout',
        'two-factor.status',
        'two-factor.setup',
        'two-factor.confirm',
        'platform.bootstrap',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->requiresTwoFactor() || $user->hasConfirmedTwoFactor()) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        // A machine-readable code lets the SPA route to enrolment rather than
        // showing a generic permission error.
        return response()->json([
            'message' => 'Set up two-factor authentication before continuing.',
            'code' => 'two_factor_setup_required',
        ], 403);
    }
}
