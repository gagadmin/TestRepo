<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_middleware_allows_authorized_users(): void
    {
        $permission = Permission::create([
            'name' => 'users.view',
            'label' => 'View users',
            'group' => 'Administration',
        ]);
        $role = Role::create(['name' => 'administrator', 'label' => 'Administrator']);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        $this->actingAs($user)
            ->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonFragment(['email' => $user->email]);
    }

    public function test_permission_middleware_rejects_unauthorized_users(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)
            ->getJson('/api/admin/users')
            ->assertForbidden();
    }
}
