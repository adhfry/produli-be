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

    /**
     * Validasi massal (temuan lapangan, UX super_admin) -- panggil validateReport() per laporan
     * dalam SATU transaksi, keputusan (is_valid/note) SAMA untuk semua ID yang dipilih. Kalau
     * salah satu gagal, semuanya di-rollback (bukan sebagian tervalidasi sebagian tidak,
     * membingungkan). Return array VisitReport (fresh) sesuai urutan report_ids masuk.
     *
     * @param  array<int>  $reportIds
     * @return array<int, VisitReport>
     */
    public function validateBulk(array $reportIds, User $superAdmin, bool $isValid, ?string $note = null): array
    {
        return DB::transaction(function () use ($reportIds, $superAdmin, $isValid, $note) {
            $results = [];
            foreach ($reportIds as $id) {
                $report = VisitReport::findOrFail($id);
                $results[] = $this->validateReport($report, $superAdmin, $isValid, $note);
            }

            return $results;
        });
    }

    /**
     * "Batalkan Validasi" (temuan lapangan, revisi Bu Kadis) -- kembalikan laporan yang SUDAH
     * divalidasi (valid/invalid) ke status 'pending' (menunggu validasi lagi), untuk kasus
     * super_admin salah pencet/keliru keputusan. Beda dari validateReport(is_valid=false) --
     * itu keputusan AKTIF "tidak valid", ini MEMBATALKAN keputusan sebelumnya seolah belum
     * pernah divalidasi sama sekali.
     */
    public function revertValidation(VisitReport $report, User $superAdmin): VisitReport
    {
        DB::transaction(function () use ($report) {
            $wasInvalid = $report->validation_status === 'invalid';

            $report->update([
                'validation_status' => 'pending',
                'validated_by' => null,
                'validated_at' => null,
                'validation_note' => null,
            ]);

            // Kalau sebelumnya invalid, assignment sempat DIBUKA LAGI jadi 'pending'
            // (validateReport() di atas). Membatalkan keputusan berarti kembalikan assignment
            // ke 'completed' JUGA -- seolah keputusan invalid itu belum pernah terjadi. Guard
            // ganda: assignment masih 'pending' DAN laporan ini masih laporan TERAKHIR/aktif --
            // kalau kader sudah mulai mengulang kunjungan (laporan baru sudah masuk), assignment
            // sudah bukan urusan laporan lama ini lagi, jangan diutak-atik.
            if ($wasInvalid && $report->assignment->status === 'pending' && $report->assignment->latestReport?->id === $report->id) {
                $report->assignment->update(['status' => 'completed']);
            }
        });

        return $report->fresh();
    }
}
