<?php

namespace App\Policies;

use App\Models\Kader;
use App\Models\User;
use App\Policies\Concerns\ScopesByPuskesmas;

/**
 * Scope kader (docs/planning/02 §7): super_admin lihat semua, admin_puskesmas/pj_prolanis
 * dibatasi ke puskesmas_id miliknya (keduanya boleh "kelola kader" -- bukan cuma PJ, admin
 * puskesmas juga admin di atasnya). Kader murni TIDAK bisa lihat daftar kader lain sama sekali
 * (bukan kebutuhannya) -- cuma boleh update profilnya sendiri lewat updateOwnProfile().
 */
class KaderPolicy
{
    use ScopesByPuskesmas;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis']);
    }

    public function view(User $user, Kader $kader): bool
    {
        return $this->sharesPuskesmas($user, $kader->puskesmas_id);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis']);
    }

    public function update(User $user, Kader $kader): bool
    {
        return $this->sharesPuskesmas($user, $kader->puskesmas_id);
    }

    /**
     * "kader hanya bisa edit profilnya sendiri, bukan punya orang lain" (docs/planning/02 §7).
     */
    public function updateOwnProfile(User $user, Kader $kader): bool
    {
        return $kader->user_id === $user->id;
    }

    /**
     * Baca profil sendiri (puskesmas + PJ) -- dipakai /onboarding supaya kader melihat unit
     * kerja & supervisor SUNGGUHAN, bukan placeholder statis. Gerbang sama persis dengan
     * updateOwnProfile (kader cuma boleh baca miliknya sendiri, bukan kader lain -- lihat viewAny
     * di atas yang sudah menolak kader murni lihat daftar/kader lain sama sekali).
     */
    public function viewOwnProfile(User $user, Kader $kader): bool
    {
        return $kader->user_id === $user->id;
    }

    public function delete(User $user, Kader $kader): bool
    {
        return false;
    }

    public function restore(User $user, Kader $kader): bool
    {
        return false;
    }

    public function forceDelete(User $user, Kader $kader): bool
    {
        return false;
    }
}
