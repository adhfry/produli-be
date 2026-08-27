<?php

namespace App\Http\Resources;

use App\Services\Risk\LabParameterReferenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu parameter hasil pemeriksaan lab TERBARU (GET /patients/{id}/lab-results) -- nilai
 * rujukan yang ditampilkan adalah nilai_rujukan ASLI dari SiLAKES (standar lab, cetakan pada
 * surat hasil), BUKAN threshold risiko PRODULI (risk_thresholds) yang cuma dipakai internal
 * utk klasifikasi -- keduanya konsep berbeda, jangan disamakan.
 *
 * reference_boundary/percent_of_reference/zone (permintaan user, fitur "Tren Hasil Pemeriksaan")
 * MEMANG dari risk_thresholds (LabParameterReferenceService) -- beda sumber dari nilai_rujukan
 * di atas, disatukan di resource yang sama krn keduanya sama-sama "info tambahan per baris hasil
 * lab", dipakai KEDUA endpoint yang pakai resource ini (/lab-results & /lab-results-history)
 * tanpa endpoint baru. null di ketiganya = parameter ini tidak punya ambang risiko terkonfigurasi
 * (mis. HDL/Microalbumin/HbA1c) -- BUKAN kegagalan, cukup dikecualikan dari grafik relatif.
 */
class LabResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // value KOLOM STRING (bisa berisi teks non-angka, mis. hasil skrining narkoba
        // "Negatif") -- is_numeric guard sama persis pola RiskClassificationService::classify(),
        // supaya cast (float) tidak diam-diam jadi 0 dan salah kecocokan ambang.
        $reference = is_numeric($this->value)
            ? app(LabParameterReferenceService::class)->evaluate($this->parameter, (float) $this->value)
            : ['reference_boundary' => null, 'percent_of_reference' => null, 'zone' => null];

        return [
            'parameter' => $this->parameter,
            'value' => $this->value,
            'satuan' => $this->satuan,
            'nilai_rujukan' => $this->nilai_rujukan,
            'class_hasil' => $this->class_hasil,
            'validation_status' => $this->validation_status,
            'tanggal_periksa' => $this->tanggal_periksa?->toDateString(),
            'reference_boundary' => $reference['reference_boundary'],
            'percent_of_reference' => $reference['percent_of_reference'],
            'zone' => $reference['zone'],
        ];
    }
}
