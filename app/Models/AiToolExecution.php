<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiToolExecution extends Model
{
    protected $fillable = [
        'conversation_id',
        'message_id',
        'user_id',
        'tool_name',
        'call_id',
        'arguments',
        'result_summary',
        'citations',
        'status',
        'duration_ms',
        'error_code',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'arguments' => 'encrypted:array',
            'result_summary' => 'encrypted:array',
            'citations' => 'encrypted:array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
