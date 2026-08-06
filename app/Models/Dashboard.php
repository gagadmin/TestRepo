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

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->roles()->where('name', 'administrator')->exists()) {
            return $query;
        }

        $roles = $user->roles()->pluck('name');

        return $query->where(function (Builder $visible) use ($user, $roles) {
            $visible->where(function (Builder $enterprise) use ($roles) {
                $enterprise->where('visibility', 'enterprise')
                    ->where(function (Builder $allowed) use ($roles) {
                        $allowed->whereRaw('1 = 0');

                        foreach ($roles as $role) {
                            $allowed->orWhereJsonContains('layout->allowed_roles', $role);
                        }
                    });
            })->orWhere(function (Builder $department) use ($user, $roles) {
                $department->where('visibility', 'department')
                    ->where(function (Builder $allowed) use ($user, $roles) {
                        $allowed->whereRaw('1 = 0');

                        if ($user->department) {
                            $allowed->orWhere('department', $user->department);
                        }

                        foreach ($roles as $role) {
                            $allowed->orWhereJsonContains('layout->allowed_roles', $role);
                        }
                    });
            });
        });
    }
}
