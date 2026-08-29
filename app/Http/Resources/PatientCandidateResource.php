<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Daftar kandidat pasien Prolanis utk dipilih ke antrian pengiriman sampel
 * (PengirimanSampelController::patientCandidates()) -- ringkas, cukup field yang dipakai UI
 * pemilihan (checkbox+cari), BUKAN detail penuh seperti PatientResource biasa.
 */
class PatientCandidateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_patient_id' => $this->external_patient_id,
            'nama' => $this->nama,
            'jenis_prolanis' => $this->jenis_prolanis,
            'kel_desa_raw' => $this->kel_desa_raw,
            'kecamatan_raw' => $this->kecamatan_raw,
            'tanggal_lab_terakhir' => $this->lab_results_max_tanggal_periksa,
        ];
    }
}
