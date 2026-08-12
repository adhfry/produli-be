<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskClassification extends Model
{
    protected $table = 'risk_classifications';

    protected $fillable = [
        'patient_id',
        'level',
        'criteria_snapshot',
        'computed_at',
        'is_latest',
        'early_detection_flag',
        'early_detection_reason',
    ];

    protected function casts(): array
    {
        return [
            'criteria_snapshot' => 'array',
            'computed_at' => 'datetime',
            'is_latest' => 'boolean',
            'early_detection_flag' => 'boolean',
            'early_detection_reason' => 'array',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientsCache::class, 'patient_id');
    }
}
