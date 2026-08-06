<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityEvent extends Model
{
    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];

    public const STATUSES = ['open', 'acknowledged', 'resolved', 'false_positive'];

    /** Relative weight used when computing the overall security score. */
    public const SEVERITY_WEIGHT = [
        'critical' => 25,
        'high' => 12,
        'medium' => 5,
        'low' => 2,
        'info' => 0,
    ];

    protected $fillable = [
        'detector', 'category', 'severity', 'title', 'description', 'status',
        'user_id', 'ip_address', 'fingerprint', 'occurrences', 'evidence',
        'recommendation', 'first_detected_at', 'last_detected_at', 'occurred_at',
        'acknowledged_at', 'acknowledged_by', 'resolved_at', 'resolved_by',
        'resolution_note', 'alerted',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'recommendation' => 'array',
            'alerted' => 'boolean',
            'occurrences' => 'integer',
            'first_detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'occurred_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'acknowledged']);
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['resolved', 'false_positive']);
    }

    public function scopeSeverity(Builder $query, string|array $severity): Builder
    {
        return $query->whereIn('severity', (array) $severity);
    }

    /** Detection latency in minutes: when it happened vs when the scan caught it. */
    public function detectionMinutes(): ?float
    {
        if (! $this->occurred_at || ! $this->first_detected_at) {
            return null;
        }

        return round($this->occurred_at->diffInSeconds($this->first_detected_at) / 60, 1);
    }

    /** Response latency in minutes: detection to resolution. */
    public function responseMinutes(): ?float
    {
        if (! $this->resolved_at || ! $this->first_detected_at) {
            return null;
        }

        return round($this->first_detected_at->diffInSeconds($this->resolved_at) / 60, 1);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'acknowledged'], true);
    }
}
