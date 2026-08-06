<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoSnapshotRow extends Model
{
    protected $fillable = [
        'seo_snapshot_id', 'key', 'clicks', 'impressions', 'ctr', 'position',
    ];

    protected function casts(): array
    {
        return [
            'clicks' => 'integer',
            'impressions' => 'integer',
            'ctr' => 'float',
            'position' => 'float',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(SeoSnapshot::class, 'seo_snapshot_id');
    }
}
