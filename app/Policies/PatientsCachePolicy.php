<?php

namespace App\Policies;

use App\Models\PatientsCache;
use App\Models\User;
use App\Policies\Concerns\ScopesByPuskesmas;

class PatientsCachePolicy
{
    use ScopesByPuskesmas;

    /**
     * Semua role yang punya sesi bisa LIHAT daftar (hasil query tetap harus di-filter
     * puskesmas_id di level query oleh controller/service — ini cuma gerbang "boleh akses fitur").
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis', 'kader']);
    }

    public function view(User $user, PatientsCache $patientsCache): bool
    {
        return $this->canAccessPatientRecord($user, $patientsCache->id, $patientsCache->puskesmas_id);
    }

    /**
     * Data pasien ditarik dari SiLAKES via SyncSilakesService — tidak ada create manual dari UI.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Field yang boleh diubah user (mis. konfirmasi geo oleh kader) tetap dalam scope puskesmas
     * yang sama; kolom mana yang benar-benar bisa diubah tetap ditentukan Form Request-nya sendiri.
     */
    public function update(User $user, PatientsCache $patientsCache): bool
    {
        return $this->canAccessPatientRecord($user, $patientsCache->id, $patientsCache->puskesmas_id);
    }

    public function delete(User $user, PatientsCache $patientsCache): bool
    {
        return false;
    }

    public function restore(User $user, PatientsCache $patientsCache): bool
    {
        return false;
    }

    public function forceDelete(User $user, PatientsCache $patientsCache): bool
    {
        return false;
    }
}
