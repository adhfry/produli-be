<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu baris riwayat klasifikasi risiko (GET /patients/{id}/risk-history, revisi Bu Kadis
 * Fase 5) -- criteria_snapshot dipakai frontend untuk seksi "Dasar Klasifikasi" (parameter,
 * nilai, ambang batas, tanggal periksa PERSIS seperti yang dipakai RiskClassificationService
 * saat itu, bukan dihitung ulang).
 */
class RiskClassificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'level' => $this->level,
            'criteria_snapshot' => $this->criteria_snapshot,
            'computed_at' => $this->computed_at?->toIso8601String(),
            'is_latest' => $this->is_latest,
            'early_detection_flag' => (bool) $this->early_detection_flag,
            'early_detection_reason' => $this->early_detection_reason,
        ];
    }
}
