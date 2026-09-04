<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Report extends Model
{
    /**
     * Roles that may be named in an `allowed_roles` grant.
     *
     * @var list<string>
     */
    public const GRANTABLE_ROLES = ['administrator', 'executive', 'manager', 'analyst'];

    /**
     * Roles whose grant may cross a department boundary.
     *
     * `scopeVisibleTo` treats `allowed_roles` as an alternative to the
     * departmental check. That is deliberate, so a genuinely cross-cutting role
     * can be given a departmental record without adding that department to
     * every holder's access profile - it is the same list the seeded Security
     * dashboard uses. The broad business roles are excluded: granting one of
     * those on a department-scoped record would publish departmental data to
     * every holder of the role and bypass the user access profile entirely.
     *
     * @var list<string>
     */
    public const CROSS_CUTTING_ROLES = ['administrator', 'security_officer'];

    protected $fillable = [
        'user_id', 'name', 'type', 'description', 'definition', 'visibility',
        'last_generated_at',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'last_generated_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(ReportSnapshot::class);
    }

    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(ReportSnapshot::class)->latestOfMany('generated_at');
    }

    public function dashboards(): BelongsToMany
    {
        return $this->belongsToMany(Dashboard::class)->withPivot(['sort_order', 'widget_size', 'settings']);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ReportSchedule::class);
    }

    public function insights(): HasMany
    {
        return $this->hasMany(AnalyticsInsight::class);
    }

    /**
     * Department-scoped visibility reads the user access profile rather than
     * the single `department` column. Report ownership is unaffected: a user
     * always sees their own reports.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdministrator()) {
            return $query;
        }

        $roles = $user->roles()->pluck('name');
        $departments = $user->accessibleDepartments();

        return $query->where(function (Builder $visible) use ($user, $departments, $roles) {
            $visible->where('user_id', $user->id)
                ->orWhere(function (Builder $enterprise) use ($roles) {
                    $enterprise->where('visibility', 'enterprise')
                        ->where(function (Builder $allowed) use ($roles) {
                            $allowed->whereRaw('1 = 0');

                            foreach ($roles as $role) {
                                $allowed->orWhereJsonContains('definition->allowed_roles', $role);
                            }
                        });
                })
                ->orWhere(function (Builder $department) use ($departments, $roles) {
                    $department->where('visibility', 'department')
                        ->where(function (Builder $allowed) use ($departments, $roles) {
                            $allowed->whereRaw('1 = 0');

                            foreach ($departments as $name) {
                                $allowed->orWhereJsonContains('definition->allowed_departments', $name)
                                    ->orWhere('definition->department', $name);
                            }

                            foreach ($roles as $role) {
                                $allowed->orWhereJsonContains('definition->allowed_roles', $role);
                            }
                        });
                });
        });
    }
}
