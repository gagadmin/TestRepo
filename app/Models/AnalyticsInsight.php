<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsInsight extends Model
{
    protected $fillable = [
        'batch_id', 'report_id', 'report_snapshot_id', 'generated_by',
        'type', 'severity', 'metric_key', 'title', 'narrative', 'payload', 'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'generated_at' => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ReportSnapshot::class, 'report_snapshot_id');
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
