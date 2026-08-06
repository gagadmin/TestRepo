<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->is_active) {
            AuditLog::create([
                'user_id' => $request->user()->id,
                'event' => 'auth.session_revoked',
                'auditable_type' => 'user',
                'auditable_id' => (string) $request->user()->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => ['reason' => 'inactive_account'],
            ]);

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'This account is inactive.'], 401);
        }

        return $next($request);
    }
}
