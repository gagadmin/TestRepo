<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A previously used password hash, retained to prevent reuse (NIST IA-5).
 *
 * Only the bcrypt hash is stored. There is no way back to the plaintext, and
 * the record is never exposed through any API.
 */
class PasswordHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'password_hash'];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
