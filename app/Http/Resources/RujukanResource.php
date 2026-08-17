<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Baris tabel /dashboard/rujukan (Fase 3) -- SATU VisitReport = satu baris, cuma yang
 * rujukan_status IS NOT NULL (RujukanService::scopedQuery()).
 */
class RujukanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $assignment = $this->assignment;
        $petugas = $assignment?->kader ?? $assignment?->tenagaKesehatan;

        return [
            'id' => $this->id,
            'assignment_id' => $this->assignment_id,
            'patient' => $assignment?->patient ? [
                'id' => $assignment->patient->id,
                'nama' => $assignment->patient->nama,
            ] : null,
            'petugas' => $petugas ? [
                'id' => $petugas->id,
                'nama' => $petugas->user?->name,
                'tipe' => $assignment->kader_id !== null ? 'kader' : 'tenaga_kesehatan',
            ] : null,
            'puskesmas' => $petugas?->puskesmas ? [
                'id' => $petugas->puskesmas->id,
                'nama' => $petugas->puskesmas->nama,
            ] : null,
            'cara_rujukan' => $this->cara_rujukan,
            'rujukan_status' => $this->rujukan_status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
