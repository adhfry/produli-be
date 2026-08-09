<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Models\VisitAssignment;
use App\Support\DataScope;

/**
 * Aturan scoping bersama untuk semua Policy (docs/planning/02 §7): super_admin lihat semua
 * data, admin_puskesmas/pj_prolanis dibatasi ke puskesmas_id miliknya sendiri, kader (murni,
 * bukan dual-role) dibatasi lebih ketat lagi ke assignment miliknya sendiri lewat
 * visit_assignments — bukan cuma "sama puskesmas" (gap yang tadinya diketahui, sekarang
 * ditutup setelah tabel kader/visit_assignments ada). Role saja TIDAK CUKUP untuk isolasi
 * data — makanya scoping ini dicek di Policy, bukan cuma middleware role.
 */
trait ScopesByPuskesmas
{
    protected function sharesPuskesmas(User $user, ?int $puskesmasId): bool
    {
        if (DataScope::isFullAccess($user)) {
            return true;
        }

        return $puskesmasId !== null && $user->puskesmas_id === $puskesmasId;
    }

    /**
     * Dipakai untuk resource yang terikat ke 1 pasien tertentu (PatientsCache, LabResultCache,
     * RiskClassification). Kader murni (bukan dual-role pj_prolanis/admin_puskesmas) HANYA
     * boleh akses pasien yang punya visit_assignments dengan dirinya — PJ Prolanis yang
     * merangkap kader tetap dapat scope puskesmas penuh (perannya yang lebih luas menang).
     */
    protected function canAccessPatientRecord(User $user, ?int $patientId, ?int $puskesmasId): bool
    {
        if (DataScope::isFullAccess($user)) {
            return true;
        }

        if (DataScope::isKaderOnly($user)) {
            if ($patientId === null || $user->kader === null) {
                return false;
            }

            return VisitAssignment::where('kader_id', $user->kader->id)
                ->where('patient_id', $patientId)
                ->exists();
        }

        return $puskesmasId !== null && $user->puskesmas_id === $puskesmasId;
    }
}
