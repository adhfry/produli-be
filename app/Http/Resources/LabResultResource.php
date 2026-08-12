<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu parameter hasil pemeriksaan lab TERBARU (GET /patients/{id}/lab-results) -- nilai
 * rujukan yang ditampilkan adalah nilai_rujukan ASLI dari SiLAKES (standar lab, cetakan pada
 * surat hasil), BUKAN threshold risiko PRODULI (risk_thresholds) yang cuma dipakai internal
 * utk klasifikasi -- keduanya konsep berbeda, jangan disamakan.
 */
class LabResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'parameter' => $this->parameter,
            'value' => $this->value,
            'satuan' => $this->satuan,
            'nilai_rujukan' => $this->nilai_rujukan,
            'class_hasil' => $this->class_hasil,
            'validation_status' => $this->validation_status,
            'tanggal_periksa' => $this->tanggal_periksa?->toDateString(),
        ];
    }
}
