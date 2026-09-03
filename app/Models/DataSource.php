<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DataSource extends Model
{
    protected $fillable = [
        'name', 'type', 'description', 'base_url', 'status', 'owner_id',
        'settings', 'last_tested_at',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'last_tested_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function apiConfiguration(): HasOne
    {
        return $this->hasOne(ApiConfiguration::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(IntegrationRun::class);
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(IntegrationRun::class)->latestOfMany();
    }

    /**
     * Two independent gates, both of which must pass for a non-owner,
     * non-administrator user:
     *
     * 1. the source itself must allow one of the user roles or one of the
     *    departments in the user access profile;
     * 2. where the administrator has configured a per-user platform allow list,
     *    this source must appear in it.
     *
     * The second gate only narrows. A user with no platform allow list keeps
     * exactly the access the first gate grants, which is the behaviour that
     * existed before access profiles.
     */
    public function isAccessibleBy(User $user): bool
    {
        if ($this->owner_id === $user->id || $user->isAdministrator()) {
            return true;
        }

        $restricted = $user->restrictedDataSourceIds();

        if ($restricted !== null && ! in_array((int) $this->id, $restricted, true)) {
            return false;
        }

        $allowedRoles = collect($this->settings['allowed_roles'] ?? []);
        $allowedDepartments = collect($this->settings['allowed_departments'] ?? []);

        return $user->roles()->whereIn('name', $allowedRoles)->exists()
            || collect($user->accessibleDepartments())
                ->intersect($allowedDepartments)
                ->isNotEmpty();
    }
}
