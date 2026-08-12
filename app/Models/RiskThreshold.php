<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiskThreshold extends Model
{
    protected $table = 'risk_thresholds';

    protected $fillable = [
        'parameter',
        'level',
        'operator',
        'is_direct_classifier',
        'threshold_min',
        'threshold_max',
        'satuan',
        'is_active',
        'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'threshold_min' => 'float',
            'threshold_max' => 'float',
            'is_active' => 'boolean',
            'is_direct_classifier' => 'boolean',
        ];
    }
}
