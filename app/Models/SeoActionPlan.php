<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoActionPlan extends Model
{
    protected $fillable = [
        'data_source_id', 'user_id', 'summary', 'items',
        'inputs_digest', 'model', 'provider',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
