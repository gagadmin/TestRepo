<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Second authorization gate for the security dashboard.
 *
 * The `security.view` permission governs functional access. This middleware
 * adds the organisational restriction on top: the caller must additionally
 * belong to the IT department or hold the administrator / security_officer
 * role. Both conditions must hold, so granting the permission alone to an
 * unrelated role does not expose security telemetry.
 */
class EnsureSecurityAccess
{
    /** Roles that may access security data regardless of department. */
    private const PRIVILEGED_ROLES = ['administrator', 'security_officer'];

    /** Departments whose staff may access security data. */
    private const ALLOWED_DEPARTMENTS = ['information technology', 'it', 'security', 'it security'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        $hasPrivilegedRole = $user->roles()
            ->whereIn('name', self::PRIVILEGED_ROLES)
            ->exists();

        $inAllowedDepartment = in_array(
            strtolower(trim((string) $user->department)),
            self::ALLOWED_DEPARTMENTS,
            true,
        );

        if ($hasPrivilegedRole || $inAllowedDepartment) {
            return $next($request);
        }

        // Record the denial: repeated attempts to reach security data by an
        // unauthorised account is itself a signal worth investigating.
        AuditLog::create([
            'user_id' => $user->id,
            'event' => 'security.access_denied',
            'auditable_type' => $user::class,
            'auditable_id' => (string) $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => str($request->userAgent() ?? '')->limit(500)->toString(),
            'metadata' => [
                'path' => $request->path(),
                'department' => $user->department,
                'reason' => 'not_it_department_or_security_role',
            ],
        ]);

        abort(403, 'Security data is restricted to the IT department and security roles.');
    }
}
