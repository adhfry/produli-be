<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PengirimanSampelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'puskesmas' => $this->whenLoaded('puskesmas', fn () => [
                'id' => $this->puskesmas->id,
                'nama' => $this->puskesmas->nama,
            ]),
            'dibuat_oleh' => $this->whenLoaded('dibuatOleh', fn () => $this->dibuatOleh ? [
                'id' => $this->dibuatOleh->id,
                'name' => $this->dibuatOleh->name,
            ] : null),
            'dikunci_at' => $this->dikunci_at?->toIso8601String(),
            'pengantar_sampel' => $this->whenLoaded('pengantarSampel', fn () => $this->pengantarSampel ? [
                'id' => $this->pengantarSampel->id,
                'nama' => $this->pengantarSampel->user?->name,
            ] : null),
            'jumlah_pasien' => $this->whenCounted('pasien'),
            'pasien' => PengirimanSampelPasienResource::collection($this->whenLoaded('pasien')),
            'catatan' => $this->catatan,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
