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
        ];
    }
}
