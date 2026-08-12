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

    public function delete(User $user, TenagaKesehatan $tenagaKesehatan): bool
    {
        return false;
    }
}
