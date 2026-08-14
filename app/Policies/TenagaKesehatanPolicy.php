<?php

namespace App\Policies;

use App\Models\TenagaKesehatan;
use App\Models\User;
use App\Policies\Concerns\ScopesByPuskesmas;

/**
 * Mirror persis KaderPolicy (lihat docblock di sana) -- admin_puskesmas/pj_prolanis boleh
 * kelola tenaga_kesehatan di puskesmas sendiri, super_admin semua.
 */
class TenagaKesehatanPolicy
{
    use ScopesByPuskesmas;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis']);
    }

    public function view(User $user, TenagaKesehatan $tenagaKesehatan): bool
    {
        return $this->sharesPuskesmas($user, $tenagaKesehatan->puskesmas_id);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis']);
    }

    public function update(User $user, TenagaKesehatan $tenagaKesehatan): bool
    {
        return $this->sharesPuskesmas($user, $tenagaKesehatan->puskesmas_id);
    }

    /**
     * Self-service (revisi Bu Kadis PMO, mode /app) -- mirror persis KaderPolicy::
     * updateOwnProfile()/viewOwnProfile(): tenaga_kesehatan cuma boleh baca/edit profilnya
     * sendiri, bukan punya orang lain.
     */
    public function updateOwnProfile(User $user, TenagaKesehatan $tenagaKesehatan): bool
    {
        return $tenagaKesehatan->user_id === $user->id;
    }

    public function viewOwnProfile(User $user, TenagaKesehatan $tenagaKesehatan): bool
    {
        return $tenagaKesehatan->user_id === $user->id;
    }

    /**
     * Hapus PERMANEN (beda dari nonaktifkan) -- mirror persis KaderPolicy::delete(), gerbang
     * service (TenagaKesehatanService::delete()) yang menolak kalau sudah ada riwayat.
     */
    public function delete(User $user, TenagaKesehatan $tenagaKesehatan): bool
    {
        return $this->sharesPuskesmas($user, $tenagaKesehatan->puskesmas_id);
    }
}
