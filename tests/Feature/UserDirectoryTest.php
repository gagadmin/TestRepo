<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Security\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Regression cover for "no users showing, no roles in the picker".
 *
 * The reported fault had two independent causes:
 *
 *  1. Router-driven navigation never called the view's data loader, so the page
 *     rendered its initial empty state. That is frontend wiring, fixed in
 *     LegacyWorkspacePage and not observable from here — these tests prove the
 *     API half is sound so the cause cannot be misattributed to the backend.
 *
 *  2. /api/admin/users sits behind the `mfa` gate. An administrator who has not
 *     enrolled a second factor receives 403, which the client reported as a
 *     permissions failure. The gate must be distinguishable from a real
 *     permissions denial.
 */
class UserDirectoryTest extends TestCase
{
    use RefreshDatabase;

    /* ==============================================================
     * The endpoint returns what the UI needs
     * ============================================================== */

    public function test_the_directory_returns_users_roles_and_departments(): void
    {
        Config::set('security.two_factor.enabled', false);
        $admin = $this->administrator();
        User::factory()->count(3)->create(['is_active' => true]);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'email', 'roles', 'two_factor_enabled', 'must_change_password']],
                'roles' => [['name', 'label']],
                'departments',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);

        // The role picker in the create dialog is populated from this key.
        $roles = collect($response->json('roles'));
        $this->assertNotEmpty($roles, 'The roles list drives the create-user picker and must not be empty.');
        $this->assertTrue($roles->contains('name', 'administrator'));
        $this->assertTrue($roles->contains('name', 'executive'));

        // Every role option carries both fields the MultiSelect binds to.
        $roles->each(function (array $role) {
            $this->assertArrayHasKey('name', $role);
            $this->assertArrayHasKey('label', $role);
            $this->assertNotEmpty($role['label']);
        });

        $this->assertGreaterThanOrEqual(4, $response->json('meta.total'));
        $this->assertCount($response->json('meta.total'), $response->json('data'));
    }

    public function test_the_acting_administrator_appears_in_the_directory(): void
    {
        Config::set('security.two_factor.enabled', false);
        $admin = $this->administrator();

        $emails = collect($this->actingAs($admin)->getJson('/api/admin/users')->json('data'))
            ->pluck('email');

        $this->assertContains($admin->email, $emails);
    }

    public function test_roles_are_returned_even_when_no_users_match_the_search(): void
    {
        Config::set('security.two_factor.enabled', false);
        $admin = $this->administrator();

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/users?search=zzz-no-such-person')
            ->assertOk();

        // The create dialog must still work from an empty result set.
        $this->assertSame([], $response->json('data'));
        $this->assertNotEmpty($response->json('roles'));
    }

    public function test_search_matches_name_email_department_and_title(): void
    {
        Config::set('security.two_factor.enabled', false);
        $admin = $this->administrator();
        User::factory()->create([
            'name' => 'Distinctive Person',
            'email' => 'distinctive@example.com',
            'department' => 'Logistics',
            'title' => 'Coordinator',
            'is_active' => true,
        ]);

        foreach (['Distinctive', 'distinctive@', 'Logistics', 'Coordinator'] as $term) {
            $data = $this->actingAs($admin)
                ->getJson('/api/admin/users?search='.urlencode($term))
                ->assertOk()
                ->json('data');

            $this->assertCount(1, $data, "Search for [{$term}] should match exactly one user.");
        }
    }

    /* ==============================================================
     * The MFA gate must be distinguishable from a permissions denial
     * ============================================================== */

    public function test_an_unenrolled_administrator_receives_the_gate_code_not_a_permissions_error(): void
    {
        Config::set('security.two_factor.enabled', true);
        Config::set('security.two_factor.required_for_all', true);

        $admin = $this->administrator(); // holds users.view but has not enrolled

        $this->actingAs($admin)
            ->getJson('/api/admin/users')
            ->assertForbidden()
            // The code is what lets the client redirect to enrolment rather than
            // claiming the account lacks permission.
            ->assertJsonPath('code', 'two_factor_setup_required');
    }

    public function test_an_enrolled_administrator_reaches_the_directory(): void
    {
        Config::set('security.two_factor.enabled', true);
        Config::set('security.two_factor.required_for_all', true);

        $admin = $this->administrator();
        $this->enrol($admin);

        $this->actingAs($admin->fresh())
            ->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_genuine_permissions_denial_carries_no_gate_code(): void
    {
        Config::set('security.two_factor.enabled', false);

        // Enrolment is irrelevant here: this account simply lacks users.view.
        $role = Role::firstOrCreate(['name' => 'analyst'], ['label' => 'Analyst']);
        $user = User::factory()->create(['is_active' => true, 'password_changed_at' => now()]);
        $user->roles()->attach($role);

        $response = $this->actingAs($user)->getJson('/api/admin/users')->assertForbidden();

        $this->assertNull(
            $response->json('code'),
            'A real permissions denial must not look like an identity gate.',
        );
    }

    public function test_a_forced_password_change_also_reports_its_own_code(): void
    {
        Config::set('security.two_factor.enabled', false);
        $admin = $this->administrator();
        $admin->forceFill(['must_change_password' => true])->save();

        $this->actingAs($admin->fresh())
            ->getJson('/api/admin/users')
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');
    }

    /* ==============================================================
     * Helpers
     * ============================================================== */

    private function administrator(): User
    {
        $role = Role::firstOrCreate(['name' => 'administrator'], ['label' => 'Administrator']);
        Role::firstOrCreate(['name' => 'executive'], ['label' => 'Executive']);

        foreach (['users.view', 'users.manage'] as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'group' => 'Administration'],
            );

            if (! $role->permissions()->where('permissions.id', $permission->id)->exists()) {
                $role->permissions()->attach($permission);
            }
        }

        $user = User::factory()->create([
            'password' => 'harbour-lantern-quiet-47',
            'is_active' => true,
            'password_changed_at' => now(),
        ]);
        $user->roles()->attach($role);

        return $user->fresh();
    }

    private function enrol(User $user): void
    {
        $service = app(TwoFactorService::class);
        $service->beginEnrolment($user);
        $user->refresh();
        $service->confirmEnrolment(
            $user,
            app(Google2FA::class)->getCurrentOtp($user->two_factor_secret),
        );
    }
}
