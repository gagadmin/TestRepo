<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportSnapshot extends Model
{
    protected $fillable = [
        'report_id', 'generated_by', 'data', 'summary', 'citations', 'row_count', 'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'encrypted:array',
            'summary' => 'encrypted:array',
            'citations' => 'encrypted:array',
            'generated_at' => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
