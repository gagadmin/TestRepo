<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Progressive lockout state for one account + source address + stage.
 */
class LoginThrottle extends Model
{
    protected $fillable = [
        'identifier_hash', 'ip_address', 'stage',
        'failure_count', 'first_failed_at', 'last_failed_at', 'locked_until',
    ];

    protected function casts(): array
    {
        return [
            'failure_count' => 'integer',
            'first_failed_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'locked_until' => 'datetime',
        ];
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function secondsRemaining(): int
    {
        if (! $this->isLocked()) {
            return 0;
        }

        return max(1, now()->diffInSeconds($this->locked_until, false));
    }
}
