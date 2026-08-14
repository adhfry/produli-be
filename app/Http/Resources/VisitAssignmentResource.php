<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VisitAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scheduled_date' => $this->scheduled_date?->toDateString(),
            'status' => $this->status,
            'priority' => $this->priority,
            // 'phone_contact' (docs: pasien Berat tanpa wilayah resolved tapi ada nomor telepon)
            // -- frontend pakai ini untuk kasih tahu kader "cari via telepon", bukan lewat peta.
            'assignment_method' => $this->assignment_method,
            'patient' => $this->whenLoaded('patient', fn () => [
                'id' => $this->patient->id,
                'nama' => $this->patient->nama,
                'alamat' => $this->patient->alamat,
                'phone' => $this->patient->phone,
                'latitude' => $this->patient->latitude !== null ? (float) $this->patient->latitude : null,
                'longitude' => $this->patient->longitude !== null ? (float) $this->patient->longitude : null,
                'geo_status' => $this->patient->geo_status,
            ]),
            'kader' => $this->whenLoaded('kader', fn () => $this->kader ? [
                'id' => $this->kader->id,
                'name' => $this->kader->user?->name,
            ] : null),
            // Petugas tenaga_kesehatan (revisi Bu Kadis, Fase 2/5) -- assignment.kader_id/
            // tenaga_kesehatan_id saling eksklusif (lihat visit_origin), null kalau assignment
            // ini milik kader.
            'tenaga_kesehatan' => $this->whenLoaded('tenagaKesehatan', fn () => $this->tenagaKesehatan ? [
                'id' => $this->tenagaKesehatan->id,
                'name' => $this->tenagaKesehatan->user?->name,
            ] : null),
            'assigned_by' => $this->whenLoaded('assignedBy', fn () => $this->assignedBy ? [
                'id' => $this->assignedBy->id,
                'name' => $this->assignedBy->name,
            ] : null),
            // Snapshot puskesmas SAAT assignment dibuat (docs/planning/02 §2a) -- relevan
            // terutama utk super_admin (lihat lintas puskesmas sekaligus di dashboard/kunjungan);
            // admin_puskesmas/pj_prolanis selalu cuma lihat puskesmasnya sendiri lewat scopedQuery.
            'puskesmas' => $this->whenLoaded('puskesmasSnapshot', fn () => $this->puskesmasSnapshot ? [
                'id' => $this->puskesmasSnapshot->id,
                'nama' => $this->puskesmasSnapshot->nama,
            ] : null),
            // Kunjungan berombongan (docs/planning/02 §16) -- kader pendamping RENCANA saat
            // assignment ini dibuat.
            'companions' => $this->whenLoaded('companions', fn () => $this->companions->map(fn ($companion) => [
                'kader_id' => $companion->kader_id,
                'nama' => $companion->kader?->user?->name,
            ])->values()),
            // 'primary'|'companion'|null -- peran petugas yang SEDANG LOGIN di assignment ini,
            // dipakai frontend beri label ("Anda mendampingi [nama primer]"). null kalau viewer
            // tidak berperan sama sekali di assignment ini. Diperluas untuk tenaga_kesehatan
            // (revisi Bu Kadis PMO) -- nakes cuma bisa 'primary' (assignment miliknya sendiri),
            // tidak ada konsep companion untuk nakes (itu cuma kader pendamping).
            'role_in_assignment' => $this->whenLoaded('companions', function () use ($request) {
                $viewer = $request->user();
                $viewerKaderId = $viewer?->kader?->id;
                $viewerTenagaKesehatanId = $viewer?->tenagaKesehatan?->id;

                if ($viewerTenagaKesehatanId !== null && $this->tenaga_kesehatan_id === $viewerTenagaKesehatanId) {
                    return 'primary';
                }

                if ($viewerKaderId === null) {
                    return null;
                }

                if ($this->kader_id === $viewerKaderId) {
                    return 'primary';
                }

                return $this->companions->contains('kader_id', $viewerKaderId) ? 'companion' : null;
            }),
            // Laporan TERBARU (bisa ada percobaan lama kalau sebelumnya invalid -> assignment
            // kembali pending -> disubmit ulang, docs/planning/02 §11) -- null kalau assignment
            // belum pernah disubmit laporan sama sekali (mis. masih pending/in_progress).
            'report' => $this->whenLoaded('latestReport', fn () => $this->latestReport ? new VisitReportResource($this->latestReport) : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
