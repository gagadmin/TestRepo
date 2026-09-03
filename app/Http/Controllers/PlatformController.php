<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DataSource;
use App\Models\Report;
use App\Models\User;
use App\Services\Security\PasswordPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlatformController extends Controller
{
    public function app(): View
    {
        return view('app');
    }

    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles.permissions');

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'title' => $user->title,
                'department' => $user->department,
                'roles' => $user->roles->pluck('name'),
                'permissions' => $user->roles
                    ->flatMap->permissions
                    ->pluck('name')
                    ->unique()
                    ->values(),
            ],

            /*
             * Identity state the SPA needs to decide whether to route the user
             * into enrolment or a forced password change before anything else.
             */
            'security' => [
                'two_factor' => [
                    'enabled' => $user->hasConfirmedTwoFactor(),
                    'required' => $user->requiresTwoFactor(),
                    'recovery_codes_remaining' => count($user->two_factor_recovery_codes ?? []),
                ],
                'password' => [
                    'must_change' => app(PasswordPolicyService::class)->mustChange($user),
                    'age_days' => $user->passwordAgeInDays(),
                ],
            ],
            'metrics' => [
                ['label' => 'Active users', 'value' => User::where('is_active', true)->count(), 'detail' => 'Authorized platform users', 'icon' => 'pi-users'],
                ['label' => 'Data sources', 'value' => DataSource::count(), 'detail' => 'Ready for integration setup', 'icon' => 'pi-database'],
                ['label' => 'Saved reports', 'value' => Report::count(), 'detail' => 'Reusable business reports', 'icon' => 'pi-file'],
                ['label' => 'Audit events', 'value' => AuditLog::count(), 'detail' => 'Recorded security events', 'icon' => 'pi-shield'],
            ],
            'phases' => [
                ['name' => 'Foundation & access', 'status' => 'active', 'progress' => 100],
                ['name' => 'Enterprise integrations', 'status' => 'active', 'progress' => 100],
                ['name' => 'AI reporting engine', 'status' => 'active', 'progress' => 100],
                ['name' => 'Dashboards & reporting', 'status' => 'active', 'progress' => 100],
                ['name' => 'Scheduled delivery', 'status' => 'active', 'progress' => 100],
                ['name' => 'Advanced analytics', 'status' => 'active', 'progress' => 100],
            ],
        ]);
    }
}
