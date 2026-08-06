<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = ['user_id', 'title', 'status', 'last_message_at'];

    protected function casts(): array
    {
        return [
            'title' => 'encrypted',
            'last_message_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function toolExecutions(): HasMany
    {
        return $this->hasMany(AiToolExecution::class);
    }
}
