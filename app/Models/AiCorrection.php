<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reviewed correction that is injected into future prompts.
 *
 * Only `approved` rows ever reach the model. A `pending` row is untrusted user
 * input: its text would otherwise become trusted guidance for every subsequent
 * question from every user.
 */
class AiCorrection extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected'];

    protected $fillable = [
        'question', 'incorrect_answer', 'correction', 'topic', 'applies_to_tools',
        'status', 'reported_by', 'conversation_id', 'reviewed_by', 'reviewed_at',
        'review_note', 'applied_count', 'last_applied_at',
    ];

    protected function casts(): array
    {
        return [
            // Questions and answers can contain business data, so they are
            // encrypted like conversation messages are.
            'question' => 'encrypted',
            'incorrect_answer' => 'encrypted',
            'correction' => 'encrypted',
            'applies_to_tools' => 'array',
            'reviewed_at' => 'datetime',
            'last_applied_at' => 'datetime',
            'applied_count' => 'integer',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }
}
