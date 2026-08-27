<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProlanisScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient' => $this->patient ? [
                'id' => $this->patient->id,
                'nama' => $this->patient->nama,
            ] : null,
            'puskesmas' => $this->puskesmas ? [
                'id' => $this->puskesmas->id,
                'nama' => $this->puskesmas->nama,
            ] : null,
            'jenis_prolanis' => $this->jenis_prolanis,
            'source_lab_date' => $this->source_lab_date?->toDateString(),
            'scheduled_date' => $this->scheduled_date->toDateString(),
            'is_manual_override' => $this->is_manual_override,
            'status' => $this->status,
            'notified_at' => $this->notified_at?->toIso8601String(),
            'updated_by' => $this->updatedBy ? ['id' => $this->updatedBy->id, 'name' => $this->updatedBy->name] : null,
        ];
    }
}
