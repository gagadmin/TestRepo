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

    public function isAccessibleBy(User $user): bool
    {
        if ($this->owner_id === $user->id || $user->roles()->where('name', 'administrator')->exists()) {
            return true;
        }

        $allowedRoles = collect($this->settings['allowed_roles'] ?? []);
        $allowedDepartments = collect($this->settings['allowed_departments'] ?? []);

        return $user->roles()->whereIn('name', $allowedRoles)->exists()
            || ($user->department && $allowedDepartments->contains($user->department));
    }
}
