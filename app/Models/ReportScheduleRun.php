<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportScheduleRun extends Model
{
    protected $fillable = [
        'report_schedule_id', 'report_id', 'triggered_by', 'report_snapshot_id',
        'status', 'trigger', 'channel_results', 'error_message', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'channel_results' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReportSchedule::class, 'report_schedule_id');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ReportSnapshot::class, 'report_snapshot_id');
    }
}
