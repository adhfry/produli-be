<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'no_hp' => $this->no_hp,
            'roles' => $this->getRoleNames(),
            'puskesmas' => $this->whenLoaded('puskesmas', fn () => $this->puskesmas ? [
                'id' => $this->puskesmas->id,
                'nama' => $this->puskesmas->nama,
            ] : null),
            // password ada di $hidden model (tidak pernah keluar di response) -- ini cuma
            // turunan boolean-nya, aman diekspos supaya frontend tahu perlu resend aktivasi atau tidak.
            'is_activated' => $this->password !== null,
            'must_change_password' => $this->must_change_password,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
