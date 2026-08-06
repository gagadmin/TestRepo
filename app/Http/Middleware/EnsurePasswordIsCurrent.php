<?php

namespace App\Http\Middleware;

use App\Services\Security\PasswordPolicyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a session whose password must be changed.
 *
 * Triggered either by an administrator or the security agent flagging suspected
 * compromise, or — if a maximum password age is configured — by expiry.
 * Rotation is disabled by default per NIST SP 800-63B.
 */
class EnsurePasswordIsCurrent
{
    private const ALLOWED_ROUTES = [
        'logout',
        'password.policy',
        'password.update',
        'platform.bootstrap',
        'two-factor.status',
    ];

    public function __construct(private readonly PasswordPolicyService $passwords) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $this->passwords->mustChange($user)) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Your password must be changed before you continue.',
            'code' => 'password_change_required',
        ], 403);
    }
}
