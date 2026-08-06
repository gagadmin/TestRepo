<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    protected $fillable = [
        'conversation_id',
        'role',
        'provider',
        'model',
        'response_id',
        'content',
        'tool_calls',
        'citations',
        'tokens',
        'input_tokens',
        'output_tokens',
        'latency_ms',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'encrypted',
            'tool_calls' => 'encrypted:array',
            'citations' => 'encrypted:array',
            'metadata' => 'encrypted:array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function toolExecutions(): HasMany
    {
        return $this->hasMany(AiToolExecution::class);
    }
}
