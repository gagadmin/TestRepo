<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Dashboard extends Model
{
    protected $fillable = [
        'name', 'slug', 'department', 'description', 'visibility', 'layout', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'layout' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function reports(): BelongsToMany
    {
        return $this->belongsToMany(Report::class)
            ->withPivot(['sort_order', 'widget_size', 'settings'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /**
     * Department-scoped visibility reads the user access profile rather than
     * the single `department` column, so a cross-functional user can be granted
     * several departments without a broader role.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdministrator()) {
            return $query;
        }

        $roles = $user->roles()->pluck('name');
        $departments = $user->accessibleDepartments();

        return $query->where(function (Builder $visible) use ($departments, $roles) {
            $visible->where(function (Builder $enterprise) use ($roles) {
                $enterprise->where('visibility', 'enterprise')
                    ->where(function (Builder $allowed) use ($roles) {
                        $allowed->whereRaw('1 = 0');

                        foreach ($roles as $role) {
                            $allowed->orWhereJsonContains('layout->allowed_roles', $role);
                        }
                    });
            })->orWhere(function (Builder $department) use ($departments, $roles) {
                $department->where('visibility', 'department')
                    ->where(function (Builder $allowed) use ($departments, $roles) {
                        $allowed->whereRaw('1 = 0');

                        if ($departments !== []) {
                            $allowed->orWhereIn('department', $departments);
                        }

                        foreach ($roles as $role) {
                            $allowed->orWhereJsonContains('layout->allowed_roles', $role);
                        }
                    });
            });
        });
    }
}
