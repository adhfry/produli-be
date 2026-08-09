<?php

namespace App\Services\Visit;

use App\Models\User;
use App\Models\VisitReport;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Alur review & validasi laporan kunjungan (docs/planning/02 §11) -- DUA tahap terpisah dari
 * submit laporan (VisitReportService) dan dari 7-layer validation (VisitValidationService, itu
 * menjamin keaslian kunjungan itu sendiri; ini menjamin ketepatan/kelengkapan datanya menurut
 * manusia):
 *
 * 1. PJ Prolanis menerima laporan dari kadernya sendiri -- pengakuan operasional, idempotent
 *    (panggilan kedua tidak menimpa waktu terima yang pertama).
 * 2. Super Admin validasi final (valid/tidak valid) -- SATU-SATUNYA yang menentukan keabsahan,
 *    bisa kapan pun, tidak perlu menunggu tahap 1. Boleh diubah ulang (super_admin bisa
 *    mengoreksi keputusan sebelumnya).
 */
class VisitReportReviewService
{
    public function __construct(private readonly NotificationService $notificationService) {}

    public function accept(VisitReport $report, User $pj): VisitReport
    {
        if ($report->pj_reviewed_at === null) {
            $report->update([
                'pj_reviewed_by' => $pj->id,
                'pj_reviewed_at' => now(),
            ]);
        }

        return $report->fresh();
    }

    public function validateReport(VisitReport $report, User $superAdmin, bool $isValid, ?string $note = null): VisitReport
    {
        DB::transaction(function () use ($report, $superAdmin, $isValid, $note) {
            $report->update([
                'validation_status' => $isValid ? 'valid' : 'invalid',
                'validated_by' => $superAdmin->id,
                'validated_at' => now(),
                'validation_note' => $note,
            ]);

            if (! $isValid) {
                // Assignment DIBUKA LAGI (bukan status baru) -- "perlu diulang" cukup dideteksi
                // dari relasi ke laporan lama berstatus invalid (docs/planning/02 §11). Laporan
                // lama SENGAJA tidak dihapus, tetap jadi jejak audit kenapa kunjungan diulang.
                $report->assignment->update(['status' => 'pending']);
            }
        });

        $report = $report->fresh();

        if (! $isValid) {
            $this->notificationService->notifyReportInvalidated($report);
        }

        return $report;
    }
}
