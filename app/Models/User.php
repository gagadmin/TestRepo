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
