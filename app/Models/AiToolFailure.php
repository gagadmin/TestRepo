<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded tool failure.
 *
 * Exists so the assistant can report why a connector did not answer, rather
 * than concluding the capability does not exist. It also gives administrators a
 * list of connectors that look configured but are failing in practice.
 */
class AiToolFailure extends Model
{
    /** Coarse reasons, used to decide what to tell the user. */
    public const REASON_NO_SOURCE = 'no_connected_source';

    public const REASON_NOT_AUTHORIZED = 'not_authorized';

    public const REASON_UPSTREAM_ERROR = 'upstream_error';

    public const REASON_MISCONFIGURED = 'misconfigured';

    protected $fillable = [
        'tool_name', 'data_source_id', 'reason', 'message',
        'occurrences', 'first_failed_at', 'last_failed_at', 'fingerprint', 'resolved',
    ];

    protected function casts(): array
    {
        return [
            'occurrences' => 'integer',
            'first_failed_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'resolved' => 'boolean',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->where('resolved', false);
    }

    public function scopeRecent(Builder $query, int $hours = 24): Builder
    {
        return $query->where('last_failed_at', '>=', now()->subHours($hours));
    }
}
