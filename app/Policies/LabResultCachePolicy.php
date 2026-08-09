<?php

namespace App\Policies;

use App\Models\LabResultCache;
use App\Models\User;
use App\Policies\Concerns\ScopesByPuskesmas;

class LabResultCachePolicy
{
    use ScopesByPuskesmas;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis', 'kader']);
    }

    /**
     * LabResultCache tidak punya puskesmas_id sendiri — merujuk longgar ke patients_cache
     * (lihat migration lab_results_cache), jadi scope diambil dari pasien terkait.
     */
    public function view(User $user, LabResultCache $labResultCache): bool
    {
        $patient = $labResultCache->patient;

        return $patient !== null && $this->canAccessPatientRecord($user, $patient->id, $patient->puskesmas_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, LabResultCache $labResultCache): bool
    {
        return false;
    }

    public function delete(User $user, LabResultCache $labResultCache): bool
    {
        return false;
    }

    public function restore(User $user, LabResultCache $labResultCache): bool
    {
        return false;
    }

    public function forceDelete(User $user, LabResultCache $labResultCache): bool
    {
        return false;
    }
}
