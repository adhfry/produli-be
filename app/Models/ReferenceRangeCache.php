<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cache lokal read-only dari SiLAKES reference_ranges, ditarik lewat
 * SyncSilakesService::syncReferenceRanges(). Dikonsumsi App\Services\Risk\SilakesReferenceRangeService
 * untuk klasifikasi presisi umur+gender di RiskClassificationService -- lihat docblock kelas itu.
 */
class ReferenceRangeCache extends Model
{
    protected $table = 'reference_ranges_cache';

    protected $fillable = [
        'parameter_key',
        'gender',
        'age_min_years',
        'age_max_years',
        'age_min_days',
        'age_max_days',
        'value_min',
        'value_max',
        'min_inclusive',
        'max_inclusive',
        'category',
        'category_label',
        'severity_rank',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'age_min_years' => 'float',
            'age_max_years' => 'float',
            'age_min_days' => 'integer',
            'age_max_days' => 'integer',
            'value_min' => 'float',
            'value_max' => 'float',
            'min_inclusive' => 'boolean',
            'max_inclusive' => 'boolean',
            'severity_rank' => 'integer',
            'synced_at' => 'datetime',
        ];
    }
}
