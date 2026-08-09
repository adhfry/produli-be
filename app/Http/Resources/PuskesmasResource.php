<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PuskesmasResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kode_internal' => $this->kode_internal,
            'nama' => $this->nama,
            'kecamatan' => $this->whenLoaded('kecamatan', fn () => $this->kecamatan ? [
                'id' => $this->kecamatan->id,
                'nama' => $this->kecamatan->nama,
            ] : null),
            'alamat' => $this->alamat,
            'no_telp' => $this->no_telp,
            'no_wa' => $this->no_wa,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'deskripsi' => $this->deskripsi,
            'status_aktif' => $this->status_aktif,
        ];
    }
}
