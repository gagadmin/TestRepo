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
 *
 * The departmental half reads the user access profile, not the single
 * `department` label, so this gate narrows and widens with the departments an
 * administrator configures - the same rule dashboards and reports follow. An
 * account given Information Technology through its profile can therefore open
 * the Security dashboard it is offered, and removing that department from a
 * profile actually withdraws access rather than leaving it behind on the label.
 * The privileged-role bypass is unchanged: those roles never depend on a
 * department.
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

        $inAllowedDepartment = collect($user->accessibleDepartments())
            ->contains(fn (string $department) => in_array(
                strtolower(trim($department)),
                self::ALLOWED_DEPARTMENTS,
                true,
            ));

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
                'permitted_departments' => $user->accessibleDepartments(),
                'reason' => 'not_it_department_or_security_role',
            ],
        ]);

        abort(403, 'Security data is restricted to the IT department and security roles.');
    }
}
