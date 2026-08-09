<?php

namespace App\Services\Visit\Validation\Layers;

use App\DTO\VisitValidationContext;
use App\DTO\VisitValidationResult;
use App\Services\Visit\Validation\VisitValidationLayer;

/**
 * Layer 7 — Offline Queue (docs/planning/02 §3). Nuxt simpan draft di IndexedDB saat offline,
 * upload ke Laravel begitu online lagi (background sync) — tetap lewat Laravel, bukan
 * direct-to-S3 (docs/planning/02 §5).
 *
 * KETERBATASAN YANG SUDAH DIKETAHUI: deteksi duplikat SUNGGUHAN (submission yang sama ter-retry
 * dan ke-upload 2x) butuh tabel `visit_reports` untuk mengecek client_submission_id yang sudah
 * pernah tersimpan — tabel itu belum ada di codebase ini (di luar scope task ini, akan jadi
 * bagian dari VisitReportService). Layer ini baru validasi STRUKTURAL: submission offline wajib
 * menyertakan client_submission_id, supaya begitu visit_reports ada, pengecekan duplikat tinggal
 * query, tidak perlu ubah kontrak/DTO ini lagi.
 */
class OfflineQueueHandler implements VisitValidationLayer
{
    public function name(): string
    {
        return 'offline_queue';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function validate(VisitValidationContext $context): VisitValidationResult
    {
        if ($context->isOffline && ! $context->clientSubmissionId) {
            return VisitValidationResult::fail(
                $this->name(),
                'Submission offline wajib menyertakan client_submission_id untuk mencegah duplikasi saat retry.',
            );
        }

        return VisitValidationResult::pass($this->name(), metadata: array_filter([
            'client_submission_id' => $context->clientSubmissionId,
        ]));
    }
}
