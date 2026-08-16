<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SENGAJA tidak menyertakan photo_path -- itu KEY internal di disk S3/MinIO (docs/planning/02
 * §5), bukan URL yang bisa langsung dibuka; butuh signed URL terpisah kalau nanti perlu
 * ditampilkan balik ke klien (di luar scope task ini).
 */
class VisitReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'assignment_id' => $this->assignment_id,
            'kondisi' => $this->kondisi,
            'catatan' => $this->catatan,
            'geo_status' => $this->geo_status,
            'geo_source' => $this->geo_source,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'face_detected' => $this->face_detected,
            'sync_status' => $this->sync_status,
            // Pemeriksaan saat kunjungan (docs/planning/02 §3) -- SEMUA opsional, bukan
            // pemeriksaan lab formal (beda dari lab_results_cache/RiskClassificationService).
            'gda' => $this->gda !== null ? (float) $this->gda : null,
            'gdp' => $this->gdp !== null ? (float) $this->gdp : null,
            'gd2jpp' => $this->gd2jpp !== null ? (float) $this->gd2jpp : null,
            'uric_acid' => $this->uric_acid !== null ? (float) $this->uric_acid : null,
            'cholesterol' => $this->cholesterol !== null ? (float) $this->cholesterol : null,
            'systolic' => $this->systolic,
            'diastolic' => $this->diastolic,
            'keluhan' => $this->keluhan,
            'tindakan' => $this->tindakan,
            // Alur rujukan (docs plan Fase 2/3) -- cara_rujukan diisi kader/nakes kalau tindakan
            // mencakup 'dirujuk_puskesmas'; rujukan_status diubah admin_puskesmas/pj_prolanis di
            // /dashboard/rujukan (Fase 3).
            'cara_rujukan' => $this->cara_rujukan,
            'rujukan_status' => $this->rujukan_status,
            // PMO mingguan kader (revisi Bu Kadis) -- opsional, terpisah dari pemeriksaan klinis
            // di atas yang jadi tanggung jawab tenaga_kesehatan.
            'kepatuhan_obat' => $this->kepatuhan_obat,
            'sisa_obat' => $this->sisa_obat,
            // Kunjungan berombongan (docs/planning/02 §16) -- kader yang AKTUAL hadir (beda
            // dari rencana companion di VisitAssignmentResource).
            'attendees' => $this->whenLoaded('attendees', fn () => $this->attendees->map(fn ($attendee) => [
                'id' => $attendee->kader_id,
                'nama' => $attendee->kader?->user?->name,
            ])->values()),
            // Alur review & validasi (docs/planning/02 §11) -- dua tahap terpisah, dua aktor.
            'pj_reviewed_at' => $this->pj_reviewed_at?->toIso8601String(),
            'pj_reviewed_by' => $this->whenLoaded('pjReviewedBy', fn () => $this->pjReviewedBy ? [
                'id' => $this->pjReviewedBy->id,
                'name' => $this->pjReviewedBy->name,
            ] : null),
            'validation_status' => $this->validation_status,
            'validated_at' => $this->validated_at?->toIso8601String(),
            'validated_by' => $this->whenLoaded('validatedBy', fn () => $this->validatedBy ? [
                'id' => $this->validatedBy->id,
                'name' => $this->validatedBy->name,
            ] : null),
            'validation_note' => $this->validation_note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
