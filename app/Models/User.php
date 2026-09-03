<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'department',
        'allowed_departments',
        'allowed_data_source_ids',
        'title',
        'is_active',
        'last_login_at',
        'must_change_password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * Two-factor material is listed explicitly: a serialised User must never
     * leak the shared secret or the recovery codes, even decrypted in memory.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'allowed_departments' => 'array',
            'allowed_data_source_ids' => 'array',
            'last_login_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'must_change_password' => 'boolean',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function passwordHistories(): HasMany
    {
        return $this->hasMany(PasswordHistory::class);
    }

    public function hasPermission(string $permission): bool
    {
        return $this->roles()
            ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
            ->exists();
    }

    /**
     * Administrators bypass every per-user visibility narrowing.
     *
     * The check was previously repeated inline as
     * `$user->roles()->where('name', 'administrator')->exists()` in each
     * visibility scope; naming it once keeps the bypass auditable in one place.
     */
    public function isAdministrator(): bool
    {
        return $this->roles()->where('name', 'administrator')->exists();
    }

    /**
     * Departments whose department-scoped dashboards and reports this user may
     * see.
     *
     * An unset profile falls back to the single `department` label so accounts
     * that predate the access profile keep exactly the visibility they had.
     *
     * @return list<string>
     */
    public function accessibleDepartments(): array
    {
        $configured = collect($this->allowed_departments ?? [])
            ->filter(fn (mixed $department) => is_string($department) && trim($department) !== '')
            ->map(fn (string $department) => trim($department));

        if ($configured->isEmpty() && filled($this->department)) {
            $configured = collect([$this->department]);
        }

        return $configured->unique()->values()->all();
    }

    public function canViewDepartment(?string $department): bool
    {
        if ($this->isAdministrator()) {
            return true;
        }

        return filled($department)
            && in_array($department, $this->accessibleDepartments(), true);
    }

    /**
     * Connected platforms this user is restricted to, or null when no per-user
     * platform restriction is configured.
     *
     * Null and a configured list are deliberately different: null means "this
     * gate does not apply", while an empty list means "no platform is
     * permitted". Callers must not conflate them.
     *
     * @return list<int>|null
     */
    public function restrictedDataSourceIds(): ?array
    {
        if ($this->allowed_data_source_ids === null) {
            return null;
        }

        return collect($this->allowed_data_source_ids)
            ->filter(fn (mixed $id) => is_numeric($id))
            ->map(fn (mixed $id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** True once the user has scanned the QR code and confirmed a valid code. */
    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null
            && filled($this->two_factor_secret);
    }

    /**
     * Whether this account is required to hold a second factor.
     *
     * Configuration decides: either everyone, or only the privileged roles.
     */
    public function requiresTwoFactor(): bool
    {
        if (! config('security.two_factor.enabled')) {
            return false;
        }

        if (config('security.two_factor.required_for_all')) {
            return true;
        }

        return $this->roles()
            ->whereIn('name', config('security.two_factor.required_roles', []))
            ->exists();
    }

    /** Days since the password was last set, or null when never recorded. */
    public function passwordAgeInDays(): ?int
    {
        return $this->password_changed_at?->diffInDays(now());
    }
}
