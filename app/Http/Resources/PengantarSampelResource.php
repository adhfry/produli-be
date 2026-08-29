<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PengantarSampelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status_aktif' => $this->status_aktif,
            'no_hp' => $this->no_hp,
            'no_wa' => $this->no_wa,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'puskesmas' => $this->whenLoaded('puskesmas', fn () => [
                'id' => $this->puskesmas->id,
                'nama' => $this->puskesmas->nama,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
