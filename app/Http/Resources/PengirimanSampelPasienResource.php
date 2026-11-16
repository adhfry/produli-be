<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PengirimanSampelPasienResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'urutan' => $this->urutan,
            'external_patient_id' => $this->external_patient_id,
            'nama_snapshot' => $this->nama_snapshot,
            'jenis_prolanis_snapshot' => $this->jenis_prolanis_snapshot,
            'is_pasien_baru' => $this->isPasienBaru(),
            'registration_proposal_ref' => $this->registration_proposal_ref,
            // Pendaftaran langsung ke SiLAKES (revisi 2026-11-17). null = pasien yang dipilih
            // dari daftar (sudah terdaftar sejak awal).
            'registrasi_status' => $this->registrasi_status,
            'registrasi_catatan' => $this->registrasi_catatan,
            'registrasi_no_reg' => $this->registrasi_no_reg,
            'registrasi_diperbarui_at' => $this->registrasi_diperbarui_at?->toIso8601String(),
        ];
    }
}
