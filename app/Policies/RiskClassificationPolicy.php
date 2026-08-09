<?php

namespace App\Policies;

use App\Models\RiskClassification;
use App\Models\User;
use App\Policies\Concerns\ScopesByPuskesmas;

class RiskClassificationPolicy
{
    use ScopesByPuskesmas;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis', 'kader']);
    }

    public function view(User $user, RiskClassification $riskClassification): bool
    {
        $patient = $riskClassification->patient;

        return $patient !== null && $this->canAccessPatientRecord($user, $patient->id, $patient->puskesmas_id);
    }

    /**
     * Dihitung otomatis oleh RiskClassificationService — tidak ada create/update/delete manual dari UI.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, RiskClassification $riskClassification): bool
    {
        return false;
    }

    public function delete(User $user, RiskClassification $riskClassification): bool
    {
        return false;
    }

    public function restore(User $user, RiskClassification $riskClassification): bool
    {
        return false;
    }

    public function forceDelete(User $user, RiskClassification $riskClassification): bool
    {
        return false;
    }
}
