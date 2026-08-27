<?php

namespace App\Policies;

use App\Models\ProlanisSchedule;
use App\Models\User;
use App\Policies\Concerns\ScopesByPuskesmas;

/**
 * Halaman /dashboard/jadwal-prolanis (permintaan user) -- admin_puskesmas/pj_prolanis kelola
 * jadwal puskesmasnya sendiri, super_admin akses semua puskesmas.
 */
class ProlanisSchedulePolicy
{
    use ScopesByPuskesmas;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis']);
    }

    public function update(User $user, ProlanisSchedule $schedule): bool
    {
        if (! $user->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis'])) {
            return false;
        }

        return $this->sharesPuskesmas($user, $schedule->puskesmas_id);
    }
}
