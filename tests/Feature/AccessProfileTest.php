<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Dashboard;
use App\Models\DataSource;
use App\Models\Permission;
use App\Models\Report;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Administrator-configurable department and platform visibility.
 *
 * Covers the security expectations of
 * `ai/test-cases/role-based-navigation-and-configurable-dashboard-access.md`:
 * the profile narrows visibility, never widens it; administrators and owners
 * bypass it; and every change to it leaves audit evidence.
 */
class AccessProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_multi_department_profile_exposes_every_granted_department(): void
    {
        $user = $this->userWithPermissions(['dashboards.view']);
        $user->update(['allowed_departments' => ['Finance', 'Procurement']]);
        $this->dashboard('Finance', 'finance');
        $this->dashboard('Procurement', 'procurement');
        $this->dashboard('Sales', 'sales');

        $response = $this->actingAs($user)->getJson('/api/dashboards')->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug')->sort()->values()->all();
        $this->assertSame(['finance', 'procurement'], $slugs);
    }

    public function test_role_grant_does_not_widen_a_department_scoped_dashboard(): void
    {
        // Regression: every seeded dashboard carried an `executive` role grant,
        // and because the visibility check ORs the role list with the access
        // profile, an executive restricted to one department still saw them all.
        $user = $this->userWithPermissions(['dashboards.view']);
        $user->roles()->attach($this->role('executive', ['dashboards.view']));
        $user->update(['allowed_departments' => ['Marketing']]);

        $this->dashboard('Marketing', 'marketing');
        $this->dashboard('Finance', 'finance', ['administrator']);
        $this->dashboard('Procurement', 'procurement', ['administrator']);

        $response = $this->actingAs($user->fresh())->getJson('/api/dashboards')->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug')->sort()->values()->all();
        $this->assertSame(['marketing'], $slugs);
    }

    public function test_role_grant_does_not_widen_a_department_scoped_report(): void
    {
        // Reports carry the same alternative role grant as dashboards, under
        // `definition->allowed_roles`, and were seeded with `executive` too.
        $user = $this->userWithPermissions(['reports.view']);
        $user->roles()->attach($this->role('executive', ['reports.view']));
        $user->update(['allowed_departments' => ['Marketing']]);

        $this->departmentReport('Marketing', 'Marketing Spend');
        $this->departmentReport('Finance', 'Financial Overview', ['administrator']);
        $this->departmentReport('Procurement', 'Procurement Spend', ['administrator']);

        $response = $this->actingAs($user->fresh())->getJson('/api/reports')->assertOk();

        $names = collect($response->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['Marketing Spend'], $names);
    }

    public function test_intentional_role_grant_still_bypasses_the_profile(): void
    {
        // The role branch is not being removed: a cross-cutting role must still
        // reach its dashboard without gaining that whole department.
        // The `security_officer` role and the Security dashboard, with its
        // deliberate role grant, are both provisioned by migration.
        $user = $this->userWithPermissions(['dashboards.view']);
        $user->roles()->attach(Role::where('name', 'security_officer')->value('id'));
        $user->update(['allowed_departments' => ['Marketing']]);

        $response = $this->actingAs($user->fresh())->getJson('/api/dashboards')->assertOk();

        $this->assertContains('security', collect($response->json('data'))->pluck('slug')->all());
    }

    public function test_no_seeded_department_scoped_record_grants_the_executive_role(): void
    {
        // The defect was in the seed data rather than in the visibility scope:
        // every department-scoped dashboard and report was seeded granting
        // `executive`, which the scope honours ahead of the access profile.
        // This asserts the invariant directly, so re-adding the grant fails.
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $granting = collect()
            ->concat(Dashboard::query()->where('visibility', 'department')->get()
                ->map(fn (Dashboard $d) => ['record' => 'dashboard:'.$d->slug, 'roles' => $d->layout['allowed_roles'] ?? []]))
            ->concat(Report::query()->where('visibility', 'department')->get()
                ->map(fn (Report $r) => ['record' => 'report:'.$r->name, 'roles' => $r->definition['allowed_roles'] ?? []]))
            ->filter(fn (array $row) => in_array('executive', $row['roles'], true))
            ->pluck('record')
            ->all();

        $this->assertSame([], $granting);
    }

    public function test_dashboard_outside_the_profile_is_not_reachable_by_slug(): void
    {
        $user = $this->userWithPermissions(['dashboards.view']);
        $user->update(['allowed_departments' => ['Finance']]);
        $this->dashboard('Procurement', 'procurement');

        $this->actingAs($user)
            ->getJson('/api/dashboards/procurement')
            ->assertNotFound();
    }

    public function test_unset_profile_falls_back_to_the_department_label(): void
    {
        // Behaviour-preserving path for accounts that predate access profiles.
        $user = $this->userWithPermissions(['dashboards.view']);
        $user->update(['department' => 'Sales', 'allowed_departments' => null]);
        $this->dashboard('Sales', 'sales');

        $this->actingAs($user)
            ->getJson('/api/dashboards')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'sales');
    }

    public function test_administrator_visibility_is_not_narrowed_by_an_empty_profile(): void
    {
        $admin = $this->administrator();
        $admin->update(['allowed_departments' => [], 'allowed_data_source_ids' => []]);
        $this->dashboard('Procurement', 'procurement');
        $source = $this->dataSource('Group ERP', []);

        $this->actingAs($admin)
            ->getJson('/api/dashboards')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'procurement');
        $this->assertTrue($source->isAccessibleBy($admin));
    }

    public function test_platform_allow_list_narrows_source_access(): void
    {
        $user = $this->userWithPermissions(['dashboards.view']);
        $user->update(['allowed_departments' => ['Finance']]);
        $permitted = $this->dataSource('Permitted ERP', ['allowed_departments' => ['Finance']]);
        $blocked = $this->dataSource('Blocked ERP', ['allowed_departments' => ['Finance']]);

        // Without a platform allow list both sources follow the source rules.
        $this->assertTrue($permitted->isAccessibleBy($user));
        $this->assertTrue($blocked->isAccessibleBy($user));

        $user->update(['allowed_data_source_ids' => [$permitted->id]]);
        $user = $user->fresh();

        $this->assertTrue($permitted->isAccessibleBy($user));
        $this->assertFalse($blocked->isAccessibleBy($user));
    }

    public function test_empty_platform_allow_list_permits_no_platform(): void
    {
        // [] and null are different states: [] must not read as "unrestricted".
        $user = $this->userWithPermissions(['dashboards.view']);
        $user->update([
            'allowed_departments' => ['Finance'],
            'allowed_data_source_ids' => [],
        ]);
        $source = $this->dataSource('Group ERP', ['allowed_departments' => ['Finance']]);

        $this->assertFalse($source->isAccessibleBy($user->fresh()));
    }

    public function test_source_owner_keeps_access_despite_the_platform_allow_list(): void
    {
        $user = $this->userWithPermissions(['dashboards.view']);
        $user->update(['allowed_data_source_ids' => []]);
        $owned = $this->dataSource('Owned ERP', [], $user);

        $this->assertTrue($owned->isAccessibleBy($user->fresh()));
    }

    public function test_dashboard_source_data_is_refused_for_a_platform_outside_the_profile(): void
    {
        $user = $this->userWithPermissions(['dashboards.view']);
        $user->update([
            'allowed_departments' => ['Finance'],
            'allowed_data_source_ids' => [],
        ]);
        $source = DataSource::create([
            'name' => 'Service Desk',
            'type' => 'freshservice',
            'base_url' => 'https://qa.freshservice.com',
            'status' => 'connected',
            'settings' => ['allowed_departments' => ['Finance']],
        ]);

        $this->actingAs($user)
            ->getJson("/api/dashboards/freshservice?data_source_id={$source->id}")
            ->assertForbidden();
    }

    public function test_administrator_can_configure_a_profile_and_the_change_is_audited(): void
    {
        $admin = $this->administrator(['users.view', 'users.manage']);
        $subject = User::factory()->create(['department' => 'Finance', 'is_active' => true]);
        $subject->roles()->attach($this->role('analyst-'.str()->random(4), []));
        $source = $this->dataSource('Group ERP', []);

        $this->actingAs($admin)
            ->putJson("/api/admin/users/{$subject->id}", [
                'name' => $subject->name,
                'email' => $subject->email,
                'department' => 'Finance',
                'title' => 'Analyst',
                'is_active' => true,
                'roles' => $subject->roles()->pluck('name')->all(),
                'allowed_departments' => ['Finance', ' Procurement '],
                'allowed_data_source_ids' => [$source->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.allowed_departments', ['Finance', 'Procurement'])
            ->assertJsonPath('data.allowed_data_source_ids', [$source->id]);

        $subject = $subject->fresh();
        $this->assertSame(['Finance', 'Procurement'], $subject->allowed_departments);
        $this->assertSame([$source->id], $subject->allowed_data_source_ids);

        $audit = AuditLog::query()->where('event', 'user.access.updated')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame((string) $subject->id, $audit->auditable_id);
        $this->assertNull($audit->metadata['before']['allowed_data_source_ids']);
        $this->assertSame(['Finance', 'Procurement'], $audit->metadata['after']['allowed_departments']);
    }

    public function test_profile_rejects_an_unknown_platform(): void
    {
        $admin = $this->administrator(['users.view', 'users.manage']);
        $subject = User::factory()->create(['is_active' => true]);
        $subject->roles()->attach($this->role('analyst-'.str()->random(4), []));

        $this->actingAs($admin)
            ->putJson("/api/admin/users/{$subject->id}", [
                'name' => $subject->name,
                'email' => $subject->email,
                'department' => null,
                'title' => null,
                'is_active' => true,
                'roles' => $subject->roles()->pluck('name')->all(),
                'allowed_data_source_ids' => [999999],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('allowed_data_source_ids.0');

        $this->assertNull($subject->fresh()->allowed_data_source_ids);
    }

    public function test_user_without_manage_permission_cannot_configure_a_profile(): void
    {
        $viewer = $this->userWithPermissions(['users.view']);
        $subject = User::factory()->create(['is_active' => true]);

        $this->actingAs($viewer)
            ->putJson("/api/admin/users/{$subject->id}", [
                'name' => $subject->name,
                'email' => $subject->email,
                'is_active' => true,
                'roles' => ['administrator'],
                'allowed_departments' => ['Finance'],
            ])
            ->assertForbidden();

        $this->assertNull($subject->fresh()->allowed_departments);
    }

    public function test_department_catalogue_includes_departments_granted_only_by_a_profile(): void
    {
        // A department that exists nowhere but in a profile must still be
        // offered by the picker, or it disappears after the first save.
        $admin = $this->administrator(['users.view', 'users.manage']);
        $subject = User::factory()->create(['department' => 'Finance', 'is_active' => true]);
        $subject->update(['allowed_departments' => ['Finance', 'Procurement']]);

        $departments = $this->actingAs($admin)
            ->getJson('/api/admin/users')
            ->assertOk()
            ->json('departments');

        $this->assertContains('Procurement', $departments);
    }

    public function test_administration_listing_exposes_platforms_without_configuration_material(): void
    {
        $admin = $this->administrator(['users.view', 'users.manage']);
        $this->dataSource('Group ERP', ['api_key' => 'must-not-appear']);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonPath('data_sources.0.name', 'Group ERP');

        $this->assertSame(
            ['id', 'name', 'type', 'status', 'authorizes_anyone'],
            array_keys($response->json('data_sources.0'))
        );
        $response->assertJsonMissing(['api_key' => 'must-not-appear']);
    }

    public function test_platform_listing_flags_a_source_that_authorizes_nobody(): void
    {
        // A source naming neither a role nor a department reaches only its owner
        // and administrators, so granting it to a user has no effect. The picker
        // needs to say so rather than leave the administrator to find out from a
        // user report - but without exposing the lists themselves.
        $admin = $this->administrator(['users.view', 'users.manage']);
        $this->dataSource('Inert ERP', ['api_key' => 'x']);
        $this->dataSource('Departmental ERP', ['allowed_departments' => ['Finance']]);
        $this->dataSource('Role ERP', ['allowed_roles' => ['manager']]);

        $sources = collect(
            $this->actingAs($admin)->getJson('/api/admin/users')->assertOk()->json('data_sources')
        )->keyBy('name');

        $this->assertFalse($sources['Inert ERP']['authorizes_anyone']);
        $this->assertTrue($sources['Departmental ERP']['authorizes_anyone']);
        $this->assertTrue($sources['Role ERP']['authorizes_anyone']);
    }

    public function test_author_can_publish_a_report_into_a_permitted_secondary_department(): void
    {
        $user = $this->userWithPermissions(['reports.view', 'reports.create']);
        $user->update(['allowed_departments' => ['Finance', 'Procurement']]);

        $this->actingAs($user->fresh())
            ->postJson('/api/reports', [
                ...$this->reportPayload(),
                'visibility' => 'department',
                'definition' => [
                    ...$this->reportPayload()['definition'],
                    'department' => 'Procurement',
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.definition.department', 'Procurement')
            ->assertJsonPath('data.definition.allowed_departments', ['Procurement']);
    }

    public function test_author_cannot_publish_a_report_into_a_department_they_cannot_view(): void
    {
        $user = $this->userWithPermissions(['reports.view', 'reports.create']);
        $user->update(['allowed_departments' => ['Finance']]);

        // Falls back to the author's own department rather than accepting the
        // submitted value, so the report cannot be pushed into Procurement.
        $this->actingAs($user->fresh())
            ->postJson('/api/reports', [
                ...$this->reportPayload(),
                'visibility' => 'department',
                'definition' => [
                    ...$this->reportPayload()['definition'],
                    'department' => 'Procurement',
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.definition.department', 'Finance')
            ->assertJsonPath('data.definition.allowed_departments', ['Finance']);
    }

    private function reportPayload(): array
    {
        return [
            'name' => 'Spend Overview',
            'type' => 'procurement_spend',
            'description' => 'QA report',
            'visibility' => 'private',
            'definition' => [
                'columns' => [
                    ['key' => 'period', 'label' => 'Period', 'type' => 'date'],
                    ['key' => 'spend', 'label' => 'Spend', 'type' => 'currency'],
                ],
            ],
        ];
    }

    /**
     * @param  list<string>|null  $allowedRoles  role grants that bypass the access
     *                                           profile, or null for none
     */
    private function dashboard(string $department, string $slug, ?array $allowedRoles = null): Dashboard
    {
        return Dashboard::create([
            'name' => $department.' Dashboard',
            'slug' => $slug,
            'department' => $department,
            'visibility' => 'department',
            'layout' => $allowedRoles === null
                ? null
                : ['columns' => 12, 'allowed_roles' => $allowedRoles],
            'is_active' => true,
        ]);
    }

    /**
     * @param  list<string>|null  $allowedRoles  role grants that bypass the access
     *                                           profile, or null for none
     */
    private function departmentReport(string $department, string $name, ?array $allowedRoles = null): Report
    {
        return Report::create([
            'user_id' => User::factory()->create()->id,
            'name' => $name,
            'type' => 'procurement_spend',
            'visibility' => 'department',
            'definition' => array_filter([
                'department' => $department,
                'allowed_departments' => [$department],
                'allowed_roles' => $allowedRoles,
            ], fn (mixed $value) => $value !== null),
        ]);
    }

    private function dataSource(string $name, array $settings, ?User $owner = null): DataSource
    {
        return DataSource::create([
            'name' => $name,
            'type' => 'erp',
            'base_url' => 'https://erp.example.com',
            'status' => 'connected',
            'owner_id' => $owner?->id,
            'settings' => $settings,
        ]);
    }

    private function role(string $name, array $permissions): Role
    {
        $role = Role::create(['name' => $name, 'label' => 'Test role']);

        foreach ($permissions as $permission) {
            $role->permissions()->attach(Permission::firstOrCreate(
                ['name' => $permission],
                ['label' => $permission, 'group' => 'Test']
            ));
        }

        return $role;
    }

    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create(['department' => 'Finance', 'is_active' => true]);
        $user->roles()->attach($this->role('role-'.str()->random(8), $permissions));

        return $user->fresh();
    }

    private function administrator(array $permissions = ['dashboards.view']): User
    {
        $user = User::factory()->create(['department' => 'Information Technology', 'is_active' => true]);
        $user->roles()->attach($this->role('administrator', $permissions));

        return $user->fresh();
    }
}
