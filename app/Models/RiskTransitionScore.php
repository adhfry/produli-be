<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris = satu transisi risk_classifications (lama -> baru) untuk satu pasien, dengan poin
 * hasil App\Services\Performance\RiskTransitionScorer. Lihat migration create_risk_transition_
 * scores_table untuk penjelasan lengkap tiap kolom & alasan idempotency.
 */
class RiskTransitionScore extends Model
{
    protected $table = 'risk_transition_scores';

    protected $fillable = [
        'patient_id',
        'puskesmas_id',
        'previous_risk_classification_id',
        'current_risk_classification_id',
        'previous_risk_level',
        'current_risk_level',
        'risk_delta',
        'base_point',
        'final_point',
        'related_validated_visit_id',
        'eligible',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'risk_delta' => 'integer',
            'base_point' => 'integer',
            'final_point' => 'integer',
            'eligible' => 'boolean',
            'calculated_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientsCache::class, 'patient_id');
    }

    public function puskesmas(): BelongsTo
    {
        return $this->belongsTo(Puskesmas::class, 'puskesmas_id');
    }

    public function previousRiskClassification(): BelongsTo
    {
        return $this->belongsTo(RiskClassification::class, 'previous_risk_classification_id');
    }

    public function currentRiskClassification(): BelongsTo
    {
        return $this->belongsTo(RiskClassification::class, 'current_risk_classification_id');
    }

    public function relatedValidatedVisit(): BelongsTo
    {
        return $this->belongsTo(VisitReport::class, 'related_validated_visit_id');
    }
}
