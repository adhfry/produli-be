<?php

namespace App\Policies;

use App\Models\User;

/**
 * Pengumuman Sistem (docs/planning/02 §13) -- GET terbuka untuk semua role login (tidak ada
 * scoping per puskesmas/kader, pengumuman selalu global), POST cuma super_admin.
 */
class SystemAnnouncementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis', 'kader']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super_admin');
    }
}
