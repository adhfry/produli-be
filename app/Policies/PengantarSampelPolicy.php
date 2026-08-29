<?php

namespace App\Policies;

use App\Models\PengantarSampel;
use App\Models\User;
use App\Policies\Concerns\ScopesByPuskesmas;

/**
 * Mirror persis TenagaKesehatanPolicy (lihat docblock di sana) -- admin_puskesmas/pj_prolanis
 * boleh kelola pengantar_sampel di puskesmas sendiri, super_admin semua. Tidak ada
 * updateOwnProfile/viewOwnProfile di sini -- role ini belum punya field yang bisa
 * di-self-service (murni identitas kurir, diisi lengkap saat registrasi).
 */
class PengantarSampelPolicy
{
    use ScopesByPuskesmas;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis']);
    }

    public function view(User $user, PengantarSampel $pengantarSampel): bool
    {
        return $this->sharesPuskesmas($user, $pengantarSampel->puskesmas_id);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis']);
    }

    public function update(User $user, PengantarSampel $pengantarSampel): bool
    {
        return $this->sharesPuskesmas($user, $pengantarSampel->puskesmas_id);
    }

    /**
     * Hapus PERMANEN (beda dari nonaktifkan) -- mirror persis TenagaKesehatanPolicy::delete().
     * Fase A belum punya tabel riwayat pengiriman sampel (baru datang di Fase C) jadi
     * PengantarSampelService::delete() belum menolak berdasarkan riwayat -- akan diperketat
     * begitu tabel pengiriman_sampel ada, mirror TenagaKesehatanService::delete().
     */
    public function delete(User $user, PengantarSampel $pengantarSampel): bool
    {
        return $this->sharesPuskesmas($user, $pengantarSampel->puskesmas_id);
    }
}
