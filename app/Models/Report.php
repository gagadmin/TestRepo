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

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->roles()->where('name', 'administrator')->exists()) {
            return $query;
        }

        $roles = $user->roles()->pluck('name');

        return $query->where(function (Builder $visible) use ($user, $roles) {
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
                ->orWhere(function (Builder $department) use ($user, $roles) {
                    $department->where('visibility', 'department')
                        ->where(function (Builder $allowed) use ($user, $roles) {
                            $allowed->whereRaw('1 = 0');

                            if ($user->department) {
                                $allowed->orWhereJsonContains('definition->allowed_departments', $user->department)
                                    ->orWhere('definition->department', $user->department);
                            }

                            foreach ($roles as $role) {
                                $allowed->orWhereJsonContains('definition->allowed_roles', $role);
                            }
                        });
                });
        });
    }
}
