<?php

use App\Models\Dashboard;
use App\Models\Report;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;

/**
 * Stop a role grant from bypassing the user access profile.
 *
 * Dashboard and report visibility treat the `allowed_roles` list as an
 * alternative grant: a department-scoped record is visible when its department
 * is in the user access profile OR one of the user roles appears in that list.
 * The role branch exists on purpose, so that a cross-cutting role such as
 * `security_officer` can be given the Security dashboard without adding
 * Information Technology to that person's departments.
 *
 * Every seeded dashboard and report, however, carried `executive`. Because the
 * check is an OR, any account holding that role matched every dashboard and
 * every departmental report, and the administrator-configured departments were
 * ignored entirely - a user restricted to Marketing still saw Finance,
 * Procurement, Asset Management and the rest.
 *
 * This removes `executive` from department-scoped records only. Deliberately
 * preserved:
 *
 * - the enterprise Executive dashboard and Executive KPI report, both
 *   role-gated by design
 * - `administrator`, which bypasses scoping everywhere already
 * - every other role, including the Security dashboard `security_officer` grant
 *
 * Executives keep their dashboards and reports through their configured
 * departments, the same way every other role does.
 *
 * Data sources are not affected: that gate refuses a source outside an explicit
 * per-user platform allow list before any role is considered, so the profile
 * already wins there.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewriteRoleGrants(
            fn (array $roles) => array_values(array_filter(
                $roles,
                fn (mixed $role) => $role !== 'executive'
            ))
        );
    }

    /**
     * Restoring the blanket grant re-opens the bypass, but a migration must be
     * reversible to be safe to deploy, so the previous state is put back
     * exactly.
     */
    public function down(): void
    {
        $this->rewriteRoleGrants(
            fn (array $roles) => in_array('executive', $roles, true)
                ? $roles
                : [...$roles, 'executive']
        );
    }

    private function rewriteRoleGrants(callable $rewrite): void
    {
        $this->rewriteColumn(Dashboard::query(), 'layout', $rewrite);
        $this->rewriteColumn(Report::query(), 'definition', $rewrite);
    }

    /**
     * Applied in PHP rather than as a JSON update statement so the same code
     * runs on both PostgreSQL and MySQL, which the platform both support. The
     * role list lives under a different key on each model, hence the column
     * argument.
     */
    private function rewriteColumn(Builder $query, string $column, callable $rewrite): void
    {
        $query->where('visibility', 'department')
            ->chunkById(100, function (Collection $records) use ($column, $rewrite): void {
                /** @var Model $record */
                foreach ($records as $record) {
                    $payload = $record->{$column} ?? [];

                    if (! is_array($payload) || ! is_array($payload['allowed_roles'] ?? null)) {
                        continue;
                    }

                    $updated = $rewrite($payload['allowed_roles']);

                    if ($updated === $payload['allowed_roles']) {
                        continue;
                    }

                    $payload['allowed_roles'] = $updated;
                    $record->{$column} = $payload;
                    $record->save();
                }
            });
    }
};
