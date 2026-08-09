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
    ];

    protected function casts(): array
    {
        return [
            'criteria_snapshot' => 'array',
            'computed_at' => 'datetime',
            'is_latest' => 'boolean',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientsCache::class, 'patient_id');
    }
}
