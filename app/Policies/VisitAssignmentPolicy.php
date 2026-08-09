<?php

namespace App\Policies;

use App\Models\Kader;
use App\Models\PatientsCache;
use App\Models\User;
use App\Models\VisitAssignment;
use App\Policies\Concerns\ScopesByPuskesmas;
use App\Support\DataScope;

/**
 * Scope visit_assignments (docs/planning/02 §7): kader murni cuma lihat/submit assignment
 * miliknya sendiri; admin_puskesmas/pj_prolanis dibatasi ke puskesmas_id_snapshot miliknya.
 */
class VisitAssignmentPolicy
{
    use ScopesByPuskesmas;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis', 'kader']);
    }

    public function view(User $user, VisitAssignment $assignment): bool
    {
        if (DataScope::isKaderOnly($user)) {
            return $user->kader !== null && $assignment->kader_id === $user->kader->id;
        }

        return $this->sharesPuskesmas($user, $assignment->puskesmas_id_snapshot);
    }

    /**
     * Butuh $patient/$kader eksplisit (bukan cuma User) karena model VisitAssignment belum ada
     * saat create -- panggil lewat $this->authorize('create', [VisitAssignment::class, $patient, $kader]).
     *
     * super_admin SENGAJA TIDAK ikut -- penugasan kunjungan adalah keputusan operasional
     * puskesmas (siapa mengunjungi siapa, kapan), bukan tindakan administrator kabupaten.
     * super_admin tetap bisa MEMVALIDASI laporan kunjungan (VisitReportController::validateReport),
     * cuma tidak menugaskan.
     */
    public function create(User $user, PatientsCache $patient, Kader $kader): bool
    {
        if (! $user->hasAnyRole(['admin_puskesmas', 'pj_prolanis'])) {
            return false;
        }

        // patient->puskesmas_id NULL berarti wilayah belum ter-resolve -- salah satu kondisi
        // pengecualian phone_contact (VisitAssignmentService::isBeratPhoneContactEligible()).
        // Otorisasi tidak bisa dibandingkan ke puskesmas yang memang belum diketahui, jadi cek
        // ini di-skip di sini; validasi SEBENARNYA (harus risk_level=Berat + nomor telepon
        // terisi) tetap ditegakkan di service, BUKAN otomatis lolos cuma karena puskesmas_id
        // null. Kader tetap wajib sepuskesmas dengan actor yang login.
        $patientCheckLolos = $patient->puskesmas_id === null
            || $this->sharesPuskesmas($user, $patient->puskesmas_id);

        return $patientCheckLolos && $this->sharesPuskesmas($user, $kader->puskesmas_id);
    }

    /**
     * Gerbang untuk POST /api/v1/visit-assignments/bulk (docs/planning/02 §12) -- kader SAMA
     * untuk seluruh pasien dalam satu batch, jadi cukup dicek sekali di sini (bukan per pasien
     * seperti create()). Scope per-pasien (kader harus sepuskesmas dengan PASIEN) tetap
     * ditegakkan lewat business-rule VisitAssignmentService::ensureKaderAvailable() per item --
     * itu otomatis menolak pasien beda puskesmas dari kader ini, jadi tidak perlu diulang di sini.
     */
    public function createBulk(User $user, Kader $kader): bool
    {
        if (! $user->hasAnyRole(['admin_puskesmas', 'pj_prolanis'])) {
            return false;
        }

        return $this->sharesPuskesmas($user, $kader->puskesmas_id);
    }

    /**
     * "kader hanya bisa submit laporan untuk assignment miliknya sendiri" (docs/planning/02 §7).
     */
    public function submitReport(User $user, VisitAssignment $assignment): bool
    {
        return $user->kader !== null && $assignment->kader_id === $user->kader->id;
    }

    public function update(User $user, VisitAssignment $assignment): bool
    {
        return false;
    }

    public function delete(User $user, VisitAssignment $assignment): bool
    {
        return false;
    }

    public function restore(User $user, VisitAssignment $assignment): bool
    {
        return false;
    }

    public function forceDelete(User $user, VisitAssignment $assignment): bool
    {
        return false;
    }
}
