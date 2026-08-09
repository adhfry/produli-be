<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KaderResource extends JsonResource
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
            'alamat' => $this->alamat,
            'gender' => $this->gender,
            'tgl_lahir' => $this->tgl_lahir?->toDateString(),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'puskesmas' => $this->whenLoaded('puskesmas', fn () => [
                'id' => $this->puskesmas->id,
                'nama' => $this->puskesmas->nama,
            ]),
            'pj' => $this->whenLoaded('pj', fn () => $this->pj ? [
                'id' => $this->pj->id,
                'name' => $this->pj->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
