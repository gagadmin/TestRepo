<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoResearchSnapshot extends Model
{
    protected $fillable = [
        'data_source_id', 'user_id', 'profile_digest', 'findings', 'model', 'provider',
    ];

    protected function casts(): array
    {
        return [
            'findings' => 'array',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
