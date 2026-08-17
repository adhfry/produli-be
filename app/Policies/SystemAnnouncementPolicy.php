<?php

namespace App\Policies;

use App\Models\SystemAnnouncement;
use App\Models\User;

/**
 * Pengumuman Sistem (docs/planning/02 §13) -- GET terbuka untuk SEMUA role login (termasuk
 * tenaga_kesehatan -- BUG lama, role ini sempat tidak disebut sama sekali di sini sehingga
 * nakes tidak bisa lihat pengumuman apa pun, ketahuan saat audit fitur), penargetan role
 * sebenarnya (target_roles) di-scope di AnnouncementService::paginateForUser()/unreadForUser(),
 * bukan di sini. POST/DELETE cuma super_admin.
 */
class SystemAnnouncementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis', 'kader', 'tenaga_kesehatan']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function delete(User $user, SystemAnnouncement $announcement): bool
    {
        return $user->hasRole('super_admin');
    }
}
