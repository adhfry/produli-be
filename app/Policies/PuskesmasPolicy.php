<?php

namespace App\Policies;

use App\Models\Puskesmas;
use App\Models\User;

class PuskesmasPolicy
{
    /**
     * Daftar/detail puskesmas bukan data privat per-pasien (beda dari PatientsCache) — semua
     * role staf boleh lihat (dipakai juga untuk dropdown/cross-reference lintas puskesmas).
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis', 'kader']);
    }

    public function view(User $user, Puskesmas $puskesmas): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis', 'kader']);
    }

    /**
     * Bikin/hapus fasilitas puskesmas = tindakan admin tingkat kabupaten, bukan operasional harian.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Update (mis. status_aktif, alamat) boleh super_admin ATAU admin_puskesmas untuk
     * puskesmas miliknya sendiri saja — bukan admin_puskesmas puskesmas lain.
     */
    public function update(User $user, Puskesmas $puskesmas): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->hasRole('admin_puskesmas') && $user->puskesmas_id === $puskesmas->id;
    }

    public function delete(User $user, Puskesmas $puskesmas): bool
    {
        return $user->hasRole('super_admin');
    }

    public function restore(User $user, Puskesmas $puskesmas): bool
    {
        return $user->hasRole('super_admin');
    }

    public function forceDelete(User $user, Puskesmas $puskesmas): bool
    {
        return $user->hasRole('super_admin');
    }
}
