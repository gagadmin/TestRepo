<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Security\PasswordPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Provisioning runs behind the mfa/password gates; disable the second
        // factor requirement so these tests exercise creation, not enrolment.
        Config::set('security.two_factor.enabled', false);
    }

    /* ==============================================================
     * Authorization
     * ============================================================== */

    public function test_creating_a_user_requires_the_manage_permission(): void
    {
        $viewer = $this->userWith(['users.view']);

        $this->actingAs($viewer)
            ->postJson('/api/admin/users', $this->payload())
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'new.starter@example.com']);
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/admin/users', $this->payload())->assertUnauthorized();
    }

    /* ==============================================================
     * Creation
     * ============================================================== */

    public function test_an_administrator_can_create_an_account(): void
    {
        $admin = $this->userWith(['users.view', 'users.manage']);

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/users', $this->payload())
            ->assertCreated()
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'name', 'email', 'roles', 'two_factor_enabled', 'must_change_password'],
                'temporary_password',
                'next_steps',
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'new.starter@example.com',
            'name' => 'New Starter',
            'department' => 'Finance',
            'is_active' => true,
        ]);

        $created = User::where('email', 'new.starter@example.com')->firstOrFail();
        $this->assertTrue($created->roles->contains('name', 'executive'));
        $this->assertTrue($response->json('data.must_change_password'));
    }

    public function test_the_temporary_password_works_and_satisfies_the_policy(): void
    {
        $admin = $this->userWith(['users.view', 'users.manage']);

        $temporary = $this->actingAs($admin)
            ->postJson('/api/admin/users', $this->payload())
            ->json('temporary_password');

        $created = User::where('email', 'new.starter@example.com')->firstOrFail();

        // It is the real credential...
        $this->assertTrue(Hash::check($temporary, $created->password));
        // ...it is stored hashed, not in plaintext...
        $this->assertNotSame($temporary, $created->password);
        // ...and it would pass the policy the user must later satisfy.
        $this->assertSame([], app(PasswordPolicyService::class)->validate($temporary));
    }

    public function test_the_new_account_must_change_its_password_before_working(): void
    {
        $admin = $this->userWith(['users.view', 'users.manage']);

        $temporary = $this->actingAs($admin)
            ->postJson('/api/admin/users', $this->payload())
            ->json('temporary_password');

        $created = User::where('email', 'new.starter@example.com')->firstOrFail();
        $this->assertTrue($created->must_change_password);

        // Sign in as the new account with the temporary password.
        $this->post('/auth/logout');
        $this->postJson('/auth/login', [
            'email' => 'new.starter@example.com',
            'password' => $temporary,
        ])->assertOk()->assertJson(['must_change_password' => true]);

        // Business endpoints are closed until the password is changed.
        $this->getJson('/api/reports')
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');

        // Changing it opens them.
        $this->putJson('/api/account/password', [
            'current_password' => $temporary,
            'password' => 'harbour-lantern-quiet-47',
            'password_confirmation' => 'harbour-lantern-quiet-47',
        ])->assertOk();

        $this->assertFalse($created->fresh()->must_change_password);
    }

    public function test_the_temporary_password_is_never_written_to_the_audit_log(): void
    {
        $admin = $this->userWith(['users.view', 'users.manage']);

        $temporary = $this->actingAs($admin)
            ->postJson('/api/admin/users', $this->payload())
            ->json('temporary_password');

        $logs = \DB::table('audit_logs')->pluck('metadata')->implode(' ');

        $this->assertStringNotContainsString($temporary, $logs);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.created']);
    }

    public function test_creation_is_audited_against_the_acting_administrator(): void
    {
        $admin = $this->userWith(['users.view', 'users.manage']);

        $this->actingAs($admin)->postJson('/api/admin/users', $this->payload());

        $created = User::where('email', 'new.starter@example.com')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.created',
            'user_id' => $admin->id,
            'auditable_id' => (string) $created->id,
        ]);
    }

    /* ==============================================================
     * Validation
     * ============================================================== */

    public function test_a_duplicate_email_is_rejected(): void
    {
        $admin = $this->userWith(['users.view', 'users.manage']);
        User::factory()->create(['email' => 'new.starter@example.com']);

        $this->actingAs($admin)
            ->postJson('/api/admin/users', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_the_email_is_normalised_to_lowercase(): void
    {
        $admin = $this->userWith(['users.view', 'users.manage']);

        $this->actingAs($admin)->postJson('/api/admin/users', [
            ...$this->payload(),
            'email' => '  New.Starter@Example.COM  ',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'new.starter@example.com']);
    }

    public function test_at_least_one_role_is_required(): void
    {
        $admin = $this->userWith(['users.view', 'users.manage']);

        $this->actingAs($admin)
            ->postJson('/api/admin/users', [...$this->payload(), 'roles' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('roles');
    }

    public function test_an_unknown_role_is_rejected(): void
    {
        $admin = $this->userWith(['users.view', 'users.manage']);

        $this->actingAs($admin)
            ->postJson('/api/admin/users', [...$this->payload(), 'roles' => ['superuser']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('roles.0');
    }

    public function test_name_and_email_are_required(): void
    {
        $admin = $this->userWith(['users.view', 'users.manage']);

        $this->actingAs($admin)
            ->postJson('/api/admin/users', ['is_active' => true, 'roles' => ['executive']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email']);
    }

    public function test_an_inactive_account_can_be_provisioned_but_cannot_sign_in(): void
    {
        $admin = $this->userWith(['users.view', 'users.manage']);

        $temporary = $this->actingAs($admin)
            ->postJson('/api/admin/users', [...$this->payload(), 'is_active' => false])
            ->assertCreated()
            ->json('temporary_password');

        $this->post('/auth/logout');

        $this->postJson('/auth/login', [
            'email' => 'new.starter@example.com',
            'password' => $temporary,
        ])->assertUnprocessable();

        $this->assertGuest();
    }

    /* ==============================================================
     * Temporary password generator
     * ============================================================== */

    public function test_generated_passwords_always_satisfy_the_policy(): void
    {
        $policy = app(PasswordPolicyService::class);

        for ($attempt = 0; $attempt < 40; $attempt++) {
            $candidate = $policy->generateTemporary();

            $this->assertSame(
                [],
                $policy->validate($candidate),
                "Generated password [{$candidate}] failed the policy.",
            );
        }
    }

    public function test_generated_passwords_are_not_predictable(): void
    {
        $policy = app(PasswordPolicyService::class);

        $generated = collect(range(1, 30))->map(fn () => $policy->generateTemporary());

        // No repeats across 30 draws.
        $this->assertSame($generated->count(), $generated->unique()->count());
    }

    /* ==============================================================
     * Profile self-service
     * ============================================================== */

    public function test_any_signed_in_user_can_read_the_password_policy(): void
    {
        $user = $this->userWith([]);

        $this->actingAs($user)
            ->getJson('/api/account/password/policy')
            ->assertOk()
            ->assertJsonStructure(['min_length', 'history_depth', 'rotation_enabled', 'guidance']);
    }

    public function test_a_user_can_change_their_own_password(): void
    {
        $user = User::factory()->create([
            'password' => 'harbour-lantern-quiet-47',
            'is_active' => true,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($user)
            ->putJson('/api/account/password', [
                'current_password' => 'harbour-lantern-quiet-47',
                'password' => 'different-meadow-compass-12',
                'password_confirmation' => 'different-meadow-compass-12',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('different-meadow-compass-12', $user->fresh()->password));
    }

    public function test_a_user_cannot_change_another_users_password(): void
    {
        // There is deliberately no endpoint accepting a target user id; the
        // route operates on the authenticated user only. This asserts the
        // absence rather than a behaviour.
        $routes = collect(app('router')->getRoutes())->map(fn ($route) => $route->uri());

        $this->assertFalse(
            $routes->contains(fn (string $uri) => str_starts_with($uri, 'api/account/password/')
                && $uri !== 'api/account/password/policy'),
            'Password change must not accept a target user parameter.',
        );
    }

    /* ==============================================================
     * Helpers
     * ============================================================== */

    private function payload(): array
    {
        return [
            'name' => 'New Starter',
            'email' => 'new.starter@example.com',
            'department' => 'Finance',
            'title' => 'Analyst',
            'is_active' => true,
            'roles' => ['executive'],
        ];
    }

    private function userWith(array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => 'test_admin'], ['label' => 'Test Admin']);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'group' => 'Administration'],
            );

            if (! $role->permissions()->where('permissions.id', $permission->id)->exists()) {
                $role->permissions()->attach($permission);
            }
        }

        // The role assigned to created users must exist for validation to pass.
        Role::firstOrCreate(['name' => 'executive'], ['label' => 'Executive']);

        $user = User::factory()->create([
            'password' => 'harbour-lantern-quiet-47',
            'is_active' => true,
            'password_changed_at' => now(),
        ]);
        $user->roles()->attach($role);

        return $user->fresh();
    }
}
