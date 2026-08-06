<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityScan extends Model
{
    protected $fillable = [
        'trigger', 'triggered_by', 'status', 'events_detected', 'events_created',
        'detectors_run', 'security_score', 'detector_results', 'error_message',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'detector_results' => 'array',
            'events_detected' => 'integer',
            'events_created' => 'integer',
            'detectors_run' => 'integer',
            'security_score' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function durationSeconds(): ?float
    {
        if (! $this->started_at || ! $this->finished_at) {
            return null;
        }

        return round($this->started_at->diffInMilliseconds($this->finished_at) / 1000, 2);
    }
}
