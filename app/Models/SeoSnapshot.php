<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoSnapshot extends Model
{
    protected $fillable = [
        'data_source_id', 'site_url', 'dimension',
        'captured_on', 'window_from', 'window_to', 'summary',
    ];

    protected function casts(): array
    {
        return [
            'captured_on' => 'date',
            'window_from' => 'date',
            'window_to' => 'date',
            'summary' => 'array',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(SeoSnapshotRow::class);
    }
}
