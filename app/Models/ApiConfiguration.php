<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiConfiguration extends Model
{
    protected $fillable = [
        'data_source_id',
        'auth_type',
        'encrypted_credentials',
        'encrypted_headers',
        'timeout_seconds',
        'retry_count',
    ];

    protected $hidden = [
        'encrypted_credentials',
        'encrypted_headers',
    ];

    protected function casts(): array
    {
        return [
            'encrypted_credentials' => 'encrypted:array',
            'encrypted_headers' => 'encrypted:array',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
