<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Dashboard;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGovernanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_administrator_can_list_users_roles_and_departments(): void
    {
        [$admin] = $this->administrator();
        User::factory()->create([
            'name' => 'Finance Manager',
            'department' => 'Finance',
            'is_active' => true,
        ]);
        Dashboard::create([
            'name' => 'Sales Dashboard',
            'slug' => 'sales',
            'department' => 'Sales',
            'visibility' => 'department',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment(['department' => 'Finance'])
            ->assertJsonFragment(['name' => 'administrator', 'label' => 'Administrator'])
            ->assertJsonPath('departments', fn (array $departments) => in_array('Sales', $departments, true));
    }

    public function test_administrator_can_assign_department_role_and_activation_state(): void
    {
        [$admin, $roles] = $this->administrator();
        $target = User::factory()->create([
            'name' => 'Support User',
            'email' => 'support@example.com',
            'department' => null,
            'title' => null,
            'is_active' => true,
        ]);
        $target->roles()->attach($roles['analyst']);

        $this->actingAs($admin)
            ->putJson("/api/admin/users/{$target->id}", [
                'name' => 'IT Support Manager',
                'email' => 'support.manager@example.com',
                'department' => 'Information Technology',
                'title' => 'Service Desk Manager',
                'is_active' => false,
                'roles' => ['manager'],
            ])
            ->assertOk()
            ->assertJsonPath('data.department', 'Information Technology')
            ->assertJsonPath('data.roles.0.name', 'manager')
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'email' => 'support.manager@example.com',
            'department' => 'Information Technology',
            'title' => 'Service Desk Manager',
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'event' => 'user.access.updated',
            'auditable_id' => (string) $target->id,
        ]);
    }

    public function test_administrator_cannot_deactivate_or_demote_their_own_account(): void
    {
        [$admin] = $this->administrator();

        $this->actingAs($admin)
            ->putJson("/api/admin/users/{$admin->id}", [
                'name' => $admin->name,
                'email' => $admin->email,
                'department' => $admin->department,
                'title' => $admin->title,
                'is_active' => false,
                'roles' => ['manager'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['roles']);

        $this->assertTrue($admin->fresh()->is_active);
        $this->assertTrue($admin->roles()->where('name', 'administrator')->exists());
    }

    public function test_audit_trail_is_bounded_and_permission_protected(): void
    {
        [$admin] = $this->administrator();
        $unauthorized = User::factory()->create(['is_active' => true]);
        AuditLog::create([
            'user_id' => $admin->id,
            'event' => 'user.access.updated',
            'auditable_type' => User::class,
            'auditable_id' => (string) $unauthorized->id,
            'metadata' => ['after' => ['department' => 'Finance']],
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/audit?event=user.access')
            ->assertOk()
            ->assertJsonPath('data.0.event', 'user.access.updated')
            ->assertJsonPath('data.0.actor.id', $admin->id)
            ->assertJsonPath('data.0.metadata.after.department', 'Finance')
            ->assertJsonPath('meta.per_page', 50);

        $this->actingAs($unauthorized)
            ->getJson('/api/admin/audit')
            ->assertForbidden();
    }

    /**
     * @return array{User, array<string, Role>}
     */
    private function administrator(): array
    {
        $permissions = collect([
            ['name' => 'users.view', 'label' => 'View users', 'group' => 'Administration'],
            ['name' => 'users.manage', 'label' => 'Manage users', 'group' => 'Administration'],
            ['name' => 'audit.view', 'label' => 'View audit logs', 'group' => 'Administration'],
        ])->mapWithKeys(function (array $attributes) {
            $permission = Permission::create($attributes);

            return [$permission->name => $permission];
        });
        $roles = collect([
            'administrator' => 'Administrator',
            'manager' => 'Manager',
            'analyst' => 'Analyst',
        ])->mapWithKeys(function (string $label, string $name) {
            $role = Role::create(compact('name', 'label'));

            return [$name => $role];
        });
        $roles['administrator']->permissions()->attach($permissions->pluck('id'));
        $admin = User::factory()->create([
            'department' => 'Information Technology',
            'title' => 'System Administrator',
            'is_active' => true,
        ]);
        $admin->roles()->attach($roles['administrator']);

        return [$admin, $roles->all()];
    }
}
