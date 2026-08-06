<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoProfile extends Model
{
    protected $fillable = [
        'data_source_id', 'categories', 'regions',
        'competitor_seeds', 'brand_terms', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'regions' => 'array',
            'competitor_seeds' => 'array',
            'brand_terms' => 'array',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    /** Lowercased brand terms for cheap substring matching against queries. */
    public function normalizedBrandTerms(): array
    {
        return collect($this->brand_terms ?? [])
            ->map(fn ($term) => strtolower(trim((string) $term)))
            ->filter()
            ->values()
            ->all();
    }
}
