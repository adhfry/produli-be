<?php

namespace App\Policies;

use App\Models\PengirimanSampel;
use App\Models\User;
use App\Policies\Concerns\ScopesByPuskesmas;

/**
 * Modul "Kirim Data Prolanis ke Labkesda Sumenep", Fase B (penyusun antrian) -- admin_puskesmas/
 * pj_prolanis boleh kelola batch pengiriman di puskesmas sendiri, super_admin semua. Mirror pola
 * PengantarSampelPolicy/TenagaKesehatanPolicy (viewAny/create global, sisanya scoped
 * sharesPuskesmas()).
 */
class PengirimanSampelPolicy
{
    use ScopesByPuskesmas;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis']);
    }

    public function view(User $user, PengirimanSampel $pengirimanSampel): bool
    {
        return $this->sharesPuskesmas($user, $pengirimanSampel->puskesmas_id);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis']);
    }

    /**
     * Tambah/hapus pasien, reorder -- semua operasi edit isi antrian sebelum dikunci, digerbangi
     * satu ability yang sama ("update"). Gerbang status (harus masih 'draft') dicek di
     * PengirimanSampelService, bukan di sini -- Policy cuma jawab "siapa", bukan "kapan".
     */
    public function update(User $user, PengirimanSampel $pengirimanSampel): bool
    {
        return $this->sharesPuskesmas($user, $pengirimanSampel->puskesmas_id);
    }

    public function lock(User $user, PengirimanSampel $pengirimanSampel): bool
    {
        return $this->sharesPuskesmas($user, $pengirimanSampel->puskesmas_id);
    }

    public function unlock(User $user, PengirimanSampel $pengirimanSampel): bool
    {
        return $this->sharesPuskesmas($user, $pengirimanSampel->puskesmas_id);
    }

    public function cancel(User $user, PengirimanSampel $pengirimanSampel): bool
    {
        return $this->sharesPuskesmas($user, $pengirimanSampel->puskesmas_id);
    }

    public function assignCourier(User $user, PengirimanSampel $pengirimanSampel): bool
    {
        return $this->sharesPuskesmas($user, $pengirimanSampel->puskesmas_id);
    }

    /**
     * Fase C -- khusus KURIR yang bersangkutan sendiri (BUKAN sharesPuskesmas(), sengaja lebih
     * ketat: staf lain sepuskesmas TIDAK boleh startOtw/confirmArrival atas nama kurir, hanya
     * boleh lihat/kelola lewat ability lain di atas). `pengantarSampel` bisa null kalau belum
     * ditugaskan -- otomatis false, bukan error.
     */
    public function isAssignedCourier(User $user, PengirimanSampel $pengirimanSampel): bool
    {
        return $pengirimanSampel->pengantarSampel?->user_id === $user->id;
    }
}
