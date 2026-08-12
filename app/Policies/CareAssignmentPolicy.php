<?php

namespace App\Policies;

use App\Models\CareAssignment;
use App\Models\User;
use App\Policies\Concerns\ScopesByPuskesmas;

/**
 * Rencana kunjungan berulang (revisi Bu Kadis) -- gerbang yang sama dengan VisitAssignmentPolicy:
 * pj_prolanis/admin_puskesmas/super_admin boleh menugaskan, tenaga_kesehatan/kader TIDAK
 * (mereka penerima tugas, bukan yang menugaskan).
 */
class CareAssignmentPolicy
{
    use ScopesByPuskesmas;

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis']);
    }

    /**
     * Kunjungan tambahan mendesak (createAdhocVisit) -- gerbang sama dengan create(), plus
     * harus sepuskesmas dengan plan yang dimaksud (admin_puskesmas/pj_prolanis tidak boleh
     * minta kunjungan mendesak untuk pasien puskesmas lain).
     */
    public function createAdhocVisit(User $user, CareAssignment $careAssignment): bool
    {
        return $this->sharesPuskesmas($user, $careAssignment->puskesmas_id_snapshot);
    }
}
