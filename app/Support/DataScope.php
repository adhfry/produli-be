<?php

namespace App\Support;

use App\Models\User;

/**
 * Primitif klasifikasi peran dipakai BERSAMA oleh Policy (cek per-record, lihat
 * App\Policies\Concerns\ScopesByPuskesmas) dan Service (query list/agregat, docs/planning/02
 * §7) -- satu tempat supaya aturan "siapa termasuk kader murni / akses penuh" tidak pernah
 * drift antara pengecekan per-record vs query list/dashboard.
 */
class DataScope
{
    public static function isFullAccess(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Kader MURNI (bukan dual-role pj_prolanis/admin_puskesmas) -- perannya yang lebih luas
     * menang kalau dual-role, jadi tidak ikut dibatasi ke assignment pribadi seperti kader murni.
     */
    public static function isKaderOnly(User $user): bool
    {
        return $user->hasRole('kader') && ! $user->hasAnyRole(['admin_puskesmas', 'pj_prolanis']);
    }

    /**
     * Tenaga Kesehatan MURNI -- simetris dengan isKaderOnly(), lihat komentar di sana.
     */
    public static function isTenagaKesehatanOnly(User $user): bool
    {
        return $user->hasRole('tenaga_kesehatan') && ! $user->hasAnyRole(['admin_puskesmas', 'pj_prolanis']);
    }

    /**
     * Pengantar Sampel MURNI -- simetris dengan isKaderOnly()/isTenagaKesehatanOnly(), lihat
     * komentar di sana.
     */
    public static function isPengantarSampelOnly(User $user): bool
    {
        return $user->hasRole('pengantar_sampel') && ! $user->hasAnyRole(['admin_puskesmas', 'pj_prolanis']);
    }
}
