<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VisitReport;

/**
 * Alur review & validasi laporan kunjungan (docs/planning/02 §11) -- DUA aksi terpisah, DUA
 * aktor berbeda, TIDAK saling bergantung urutan (super_admin bisa validasi kapan pun tanpa
 * menunggu PJ menerima dulu -- kewenangan tertinggi). admin_puskesmas TIDAK terlibat aksi apa
 * pun di alur ini (monitoring saja, lihat docs/planning/02 §7).
 */
class VisitReportPolicy
{
    /**
     * PJ Prolanis menerima laporan HANYA dari kader/tenaga_kesehatan yang disupervisinya sendiri
     * (kader.pj_id = user.id, atau tenaga_kesehatan.pj_id = user.id -- revisi Bu Kadis PMO,
     * assignment.kader_id/tenaga_kesehatan_id saling eksklusif jadi salah satu selalu null).
     */
    public function accept(User $user, VisitReport $visitReport): bool
    {
        if (! $user->hasRole('pj_prolanis')) {
            return false;
        }

        $worker = $visitReport->assignment->kader ?? $visitReport->assignment->tenagaKesehatan;

        return $worker !== null && $worker->pj_id === $user->id;
    }

    /**
     * Validasi final -- SATU-SATUNYA wewenang super_admin, tidak ada scoping puskesmas.
     */
    public function validateReport(User $user): bool
    {
        return $user->hasRole('super_admin');
    }
}
