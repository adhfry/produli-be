<?php

namespace App\Services\Kader;

use App\Models\Kader;
use App\Models\User;
use App\Services\Auth\AccountActivationService;
use App\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registrasi kader baru oleh PJ Prolanis/admin_puskesmas/super_admin, dan self-service update
 * profil kader sendiri (docs/planning/02 §7). `nama`/`email` sengaja tidak disimpan di tabel
 * kader -- selalu lewat users.name/users.email (relasi user_id), makanya register() ikut
 * mengurus baris users-nya (find-or-create by email), bukan cuma baris kader.
 */
class KaderService
{
    public function __construct(private readonly AccountActivationService $accountActivationService) {}

    /**
     * @param  array{name: string, email: string, no_hp: string, no_wa?: ?string, alamat?: ?string,
     *     gender?: ?string, tgl_lahir?: ?string, puskesmas_id?: ?int, pj_id?: ?int}  $data
     */
    public function register(User $registrant, array $data): Kader
    {
        $puskesmasId = $this->resolvePuskesmasId($registrant, $data);
        $pjId = $this->resolvePjId($registrant, $data, $puskesmasId);

        $user = null;

        $kader = DB::transaction(function () use ($data, $puskesmasId, $pjId, &$user) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => null,
                    // Dipakai scoping umum (ScopesByPuskesmas) di luar Kader.puskesmas_id sendiri
                    // -- lihat resend aktivasi (AuthController) yang cek puskesmas_id di users.
                    'puskesmas_id' => $puskesmasId,
                ],
            );

            if ($user->kader()->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['User dengan email ini sudah terdaftar sebagai kader.'],
                ]);
            }

            if (! $user->hasRole('kader')) {
                $user->assignRole('kader');
            }

            return Kader::create([
                'user_id' => $user->id,
                'pj_id' => $pjId,
                'puskesmas_id' => $puskesmasId,
                'status_aktif' => true,
                'no_hp' => $data['no_hp'],
                'no_wa' => $data['no_wa'] ?? null,
                'alamat' => $data['alamat'] ?? null,
                'gender' => $data['gender'] ?? null,
                'tgl_lahir' => $data['tgl_lahir'] ?? null,
            ]);
        });

        // Cek wasRecentlyCreated di instance $user ASLI yang dibuat di dalam transaksi -- kalau
        // dicek lewat $kader->user (lazy load ulang dari DB), flag ini SELALU false karena itu
        // instance model yang BARU di-query, bukan instance yang barusan di-insert.
        if ($user->wasRecentlyCreated) {
            $this->accountActivationService->inviteNewUser($user, $registrant);
        }

        return $kader;
    }

    /**
     * @param  array{no_wa?: ?string, alamat?: ?string, gender?: ?string, tgl_lahir?: ?string}  $data
     */
    public function updateOwnProfile(Kader $kader, array $data): Kader
    {
        $kader->update(Arr::only($data, ['no_wa', 'alamat', 'gender', 'tgl_lahir']));

        return $kader->fresh();
    }

    /**
     * Aktifkan/nonaktifkan kader (docs/planning §7 lanjutan) -- nonaktif TIDAK menghapus riwayat
     * assignment/laporan, cuma menghentikan penugasan BARU (kader nonaktif otomatis tersaring
     * dari scopedActiveKaders() dashboard & dari opsi "Tugaskan Kader" di /dashboard/kunjungan,
     * sama pola dengan status_aktif Puskesmas).
     */
    public function setActive(Kader $kader, bool $active): Kader
    {
        $kader->update(['status_aktif' => $active]);

        return $kader->fresh();
    }

    /**
     * @return Builder<Kader>
     */
    public function scopedQuery(User $user): Builder
    {
        if (DataScope::isFullAccess($user)) {
            return Kader::query();
        }

        if ($user->puskesmas_id === null) {
            return Kader::query()->whereRaw('1 = 0');
        }

        return Kader::query()->where('puskesmas_id', $user->puskesmas_id);
    }

    /**
     * @param  array{puskesmas_id?: ?int}  $data
     */
    private function resolvePuskesmasId(User $registrant, array $data): int
    {
        if (DataScope::isFullAccess($registrant)) {
            if (empty($data['puskesmas_id'])) {
                throw ValidationException::withMessages([
                    'puskesmas_id' => ['Wajib diisi untuk super_admin (tidak punya puskesmas sendiri).'],
                ]);
            }

            return (int) $data['puskesmas_id'];
        }

        // admin_puskesmas/pj_prolanis: dipaksa ke puskesmas sendiri, abaikan input klien kalau
        // ada (defense in depth -- sama pola seperti is_prolanis=1 di SilakesApiClient).
        if ($registrant->puskesmas_id === null) {
            throw ValidationException::withMessages([
                'puskesmas_id' => ['Akun Anda belum di-assign ke puskesmas mana pun.'],
            ]);
        }

        return $registrant->puskesmas_id;
    }

    /**
     * @param  array{pj_id?: ?int}  $data
     */
    private function resolvePjId(User $registrant, array $data, int $puskesmasId): ?int
    {
        if ($registrant->hasRole('pj_prolanis')) {
            // PJ yang mendaftarkan -> otomatis jadi supervisor kader ini, abaikan input klien
            // (ini kasus mayoritas -- pj_prolanis TIDAK perlu pilih dirinya sendiri manual).
            return $registrant->id;
        }

        // admin_puskesmas/super_admin: opsional, boleh assign PJ tertentu (lihat pjOptionsQuery()
        // untuk daftar pilihan yang valid) atau kosongkan dulu.
        $pjId = $data['pj_id'] ?? null;

        if ($pjId === null) {
            return null;
        }

        $pj = User::find($pjId);

        // PJ HARUS sepuskesmas dengan kader yang didaftarkan -- jangan percaya input klien
        // (defense in depth, pola sama seperti resolvePuskesmasId()).
        if (! $pj || ! $pj->hasRole('pj_prolanis') || $pj->puskesmas_id !== $puskesmasId) {
            throw ValidationException::withMessages([
                'pj_id' => ['User yang ditunjuk sebagai PJ tidak valid, bukan pj_prolanis, atau beda puskesmas.'],
            ]);
        }

        return $pjId;
    }

    /**
     * Daftar pilihan PJ Prolanis untuk dropdown registrasi kader (dipakai admin_puskesmas/
     * super_admin -- pj_prolanis tidak perlu ini, pj_id-nya otomatis lewat resolvePjId()).
     * Scope sama seperti resolvePuskesmasId(): admin_puskesmas dipaksa ke puskesmas sendiri
     * (abaikan input klien), super_admin boleh filter opsional lewat $puskesmasId (tidak
     * punya puskesmas sendiri untuk dipaksakan).
     *
     * @return Builder<User>
     */
    public function pjOptionsQuery(User $registrant, ?int $puskesmasId = null): Builder
    {
        $query = User::role('pj_prolanis');

        if (DataScope::isFullAccess($registrant)) {
            return $puskesmasId !== null ? $query->where('puskesmas_id', $puskesmasId) : $query;
        }

        if ($registrant->puskesmas_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('puskesmas_id', $registrant->puskesmas_id);
    }
}
