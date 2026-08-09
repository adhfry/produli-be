<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shaping response pasien -- SENGAJA tidak menyertakan nik_hash (kunci pencocokan internal,
 * tidak berguna & tidak perlu diekspos ke frontend) meski model aslinya menyimpan itu.
 */
class PatientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_patient_id' => $this->external_patient_id,
            'no_reg' => $this->no_reg,
            'nama' => $this->nama,
            'gender' => $this->gender,
            'tgl_lahir' => $this->tgl_lahir?->toDateString(),
            'phone' => $this->phone,
            'alamat' => $this->alamat,
            'rt_rw' => $this->rt_rw,
            'kel_desa_raw' => $this->kel_desa_raw,
            'kecamatan_raw' => $this->kecamatan_raw,
            'is_prolanis' => $this->is_prolanis,
            'jenis_prolanis' => $this->jenis_prolanis,
            'is_perokok' => $this->is_perokok,
            'jenis_perokok' => $this->jenis_perokok,
            'wilayah_status' => $this->wilayah_status,
            'puskesmas_resolution_method' => $this->puskesmas_resolution_method,
            'desa' => $this->whenLoaded('desa', fn () => [
                'id' => $this->desa->id,
                'nama' => $this->desa->nama,
            ]),
            'puskesmas' => $this->whenLoaded('puskesmas', fn () => [
                'id' => $this->puskesmas->id,
                'nama' => $this->puskesmas->nama,
            ]),
            'geo_status' => $this->geo_status,
            'geo_source' => $this->geo_source,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'risk_level' => $this->whenLoaded(
                'latestRiskClassification',
                fn () => $this->latestRiskClassification?->level,
            ),
            'risk_computed_at' => $this->whenLoaded(
                'latestRiskClassification',
                fn () => $this->latestRiskClassification?->computed_at?->toIso8601String(),
            ),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
        ];
    }
}
