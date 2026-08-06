<?php

namespace App\Models;

use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportSchedule extends Model
{
    protected $fillable = [
        'report_id', 'created_by', 'frequency', 'cron_expression', 'timezone',
        'format', 'filters', 'delivery_channels', 'recipients', 'is_active',
        'next_run_at', 'last_run_at', 'last_status', 'failure_count', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'delivery_channels' => 'array',
            'recipients' => 'encrypted:array',
            'is_active' => 'boolean',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ReportScheduleRun::class);
    }

    public function calculateNextRun(?\DateTimeInterface $from = null): \DateTimeInterface
    {
        return (new CronExpression($this->cron_expression))
            ->getNextRunDate($from ?? now(), 0, false, $this->timezone);
    }
}
