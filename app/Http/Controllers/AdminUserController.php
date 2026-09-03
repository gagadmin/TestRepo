<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserAccessRequest;
use App\Http\Requests\UserCreateRequest;
use App\Models\AuditLog;
use App\Models\Dashboard;
use App\Models\DataSource;
use App\Models\Role;
use App\Models\User;
use App\Services\Security\PasswordPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminUserController extends Controller
{
    public function __construct(private readonly PasswordPolicyService $passwords) {}

    /**
     * Provision an account.
     *
     * The temporary password is generated rather than supplied: an administrator
     * inventing a password tends to produce a weak or reused one, and it would
     * then be typed into a chat window. It is returned exactly once in this
     * response, never stored in plaintext, and the account must change it before
     * it can do anything (`must_change_password`).
     */
    public function store(UserCreateRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $temporaryPassword = $this->passwords->generateTemporary();

        $user = DB::transaction(function () use ($validated, $temporaryPassword) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $temporaryPassword, // hashed by the model cast
                'department' => $validated['department'],
                'allowed_departments' => $validated['allowed_departments'],
                'allowed_data_source_ids' => $validated['allowed_data_source_ids'],
                'title' => $validated['title'],
                'is_active' => $validated['is_active'],
            ]);

            $user->forceFill([
                'must_change_password' => true,
                'password_changed_at' => now(),
                // Not email-verified: this account was created by an
                // administrator, not by the person who owns the address.
                'email_verified_at' => null,
            ])->save();

            $user->roles()->sync(
                Role::query()->whereIn('name', $validated['roles'])->pluck('id')
            );

            return $user;
        });

        AuditLog::create([
            'user_id' => $request->user()->id,
            'event' => 'user.created',
            'auditable_type' => User::class,
            'auditable_id' => (string) $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                // The password is never audited, only the fact of provisioning.
                'name' => $user->name,
                'department' => $user->department,
                'allowed_departments' => $user->allowed_departments,
                'allowed_data_source_ids' => $user->allowed_data_source_ids,
                'roles' => $validated['roles'],
                'is_active' => $user->is_active,
            ],
        ]);

        return response()->json([
            'message' => "{$user->name}'s account has been created.",
            'data' => $this->serialize($user->load('roles:id,name,label')),
            'temporary_password' => $temporaryPassword,
            'next_steps' => [
                'Share this password with the user through a secure channel — not email or chat.',
                'They must change it the first time they sign in.',
                'They will also be asked to set up two-step verification.',
            ],
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));
        $users = User::query()
            ->with('roles:id,name,label')
            ->when($search !== '', fn ($query) => $query->where(function ($match) use ($search) {
                $match->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%");
            }))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $departments = User::query()
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->distinct()
            ->pluck('department')
            ->merge(Dashboard::query()->whereNotNull('department')->distinct()->pluck('department'))
            // Departments granted only through an access profile would otherwise
            // vanish from the picker after the first save.
            ->merge(User::query()
                ->whereNotNull('allowed_departments')
                ->pluck('allowed_departments')
                ->flatMap(fn (mixed $departments) => is_array($departments) ? $departments : []))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return response()->json([
            'data' => $users->getCollection()->map(fn (User $user) => $this->serialize($user)),
            'roles' => Role::query()->orderBy('label')->get(['name', 'label']),
            'departments' => $departments,

            /*
             * The connected platforms an administrator can grant. Name, type,
             * and status only — no credential or configuration material is
             * exposed to build an access picker.
             */
            'data_sources' => DataSource::query()
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'status'])
                ->map(fn (DataSource $source) => [
                    'id' => $source->id,
                    'name' => $source->name,
                    'type' => $source->type,
                    'status' => $source->status,
                ]),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function update(UserAccessRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();
        $roleNames = $validated['roles'];
        $willBeActiveAdministrator = $validated['is_active']
            && in_array('administrator', $roleNames, true);
        $isCurrentUser = $request->user()->is($user);

        if ($isCurrentUser && ! $willBeActiveAdministrator) {
            throw ValidationException::withMessages([
                'roles' => 'You cannot deactivate your own account or remove your administrator role.',
            ]);
        }

        $isActiveAdministrator = $user->is_active
            && $user->roles()->where('name', 'administrator')->exists();

        if ($isActiveAdministrator && ! $willBeActiveAdministrator) {
            $otherActiveAdministrators = User::query()
                ->whereKeyNot($user->id)
                ->where('is_active', true)
                ->whereHas('roles', fn ($query) => $query->where('name', 'administrator'))
                ->exists();

            if (! $otherActiveAdministrators) {
                throw ValidationException::withMessages([
                    'roles' => 'At least one active administrator account must remain.',
                ]);
            }
        }

        $before = [
            'department' => $user->department,
            'allowed_departments' => $user->allowed_departments,
            'allowed_data_source_ids' => $user->allowed_data_source_ids,
            'title' => $user->title,
            'is_active' => $user->is_active,
            'roles' => $user->roles()->pluck('name')->sort()->values()->all(),
        ];

        DB::transaction(function () use ($validated, $roleNames, $user): void {
            $user->update([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'department' => $validated['department'],
                'allowed_departments' => $validated['allowed_departments'],
                'allowed_data_source_ids' => $validated['allowed_data_source_ids'],
                'title' => $validated['title'],
                'is_active' => $validated['is_active'],
            ]);
            $user->roles()->sync(Role::query()->whereIn('name', $roleNames)->pluck('id'));
        });

        $updated = $user->fresh('roles:id,name,label');
        AuditLog::create([
            'user_id' => $request->user()->id,
            'event' => 'user.access.updated',
            'auditable_type' => User::class,
            'auditable_id' => (string) $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                'before' => $before,
                'after' => [
                    'department' => $updated->department,
                    'allowed_departments' => $updated->allowed_departments,
                    'allowed_data_source_ids' => $updated->allowed_data_source_ids,
                    'title' => $updated->title,
                    'is_active' => $updated->is_active,
                    'roles' => $updated->roles->pluck('name')->sort()->values()->all(),
                ],
            ],
        ]);

        return response()->json([
            'message' => 'User access updated.',
            'data' => $this->serialize($updated),
        ]);
    }

    private function serialize(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'department' => $user->department,

            /*
             * The configured profile is returned raw (null stays null) so the
             * administration screen can distinguish "not configured" from
             * "configured as empty" — they mean different things to the
             * visibility gates. `effective_departments` is what the gates will
             * actually compare against.
             */
            'allowed_departments' => $user->allowed_departments,
            'allowed_data_source_ids' => $user->allowed_data_source_ids,
            'effective_departments' => $user->accessibleDepartments(),
            'title' => $user->title,
            'is_active' => $user->is_active,
            'last_login_at' => $user->last_login_at,
            'roles' => $user->roles->map(fn (Role $role) => [
                'name' => $role->name,
                'label' => $role->label,
            ])->values(),

            // Identity state, so the admin list can show who is still outstanding.
            'two_factor_enabled' => $user->hasConfirmedTwoFactor(),
            'must_change_password' => (bool) $user->must_change_password,
        ];
    }
}
