<?php

namespace App\Services\PengantarSampel;

use App\Models\PengantarSampel;
use App\Models\User;
use App\Services\Auth\AccountActivationService;
use App\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registrasi pengantar_sampel baru -- mirror persis TenagaKesehatanService (lihat docblock di
 * sana), scoping puskesmas identik. Role ini murni identitas kurir untuk modul Kirim Data
 * Prolanis ke Labkesda: tidak ada pj_id/alamat/gender/tgl_lahir seperti tenaga_kesehatan,
 * karena kurir tidak pernah jadi subjek pemeriksaan atau butuh supervisi PJ perorangan.
 */
class PengantarSampelService
{
    public function __construct(private readonly AccountActivationService $accountActivationService) {}

    /**
     * @param  array{name: string, email: string, no_hp: string, no_wa?: ?string, puskesmas_id?: ?int}  $data
     */
    public function register(User $registrant, array $data): PengantarSampel
    {
        $puskesmasId = $this->resolvePuskesmasId($registrant, $data);

        $user = null;

        $pengantarSampel = DB::transaction(function () use ($data, $puskesmasId, &$user) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => null,
                    'puskesmas_id' => $puskesmasId,
                ],
            );

            if ($user->pengantarSampel()->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['User dengan email ini sudah terdaftar sebagai pengantar sampel.'],
                ]);
            }

            if (! $user->hasRole('pengantar_sampel')) {
                $user->assignRole('pengantar_sampel');
            }

            return PengantarSampel::create([
                'user_id' => $user->id,
                'puskesmas_id' => $puskesmasId,
                'status_aktif' => true,
                'no_hp' => $data['no_hp'],
                'no_wa' => $data['no_wa'] ?? null,
            ]);
        });

        if ($user->wasRecentlyCreated) {
            $this->accountActivationService->inviteNewUser($user, $registrant);
        }

        return $pengantarSampel;
    }

    public function setActive(PengantarSampel $pengantarSampel, bool $active): PengantarSampel
    {
        $pengantarSampel->update(['status_aktif' => $active]);

        return $pengantarSampel->fresh();
    }

    /**
     * Mirror persis TenagaKesehatanService::update() -- puskesmas_id SENGAJA tidak bisa diubah
     * di sini.
     *
     * @param  array{name?: string, email?: string, no_hp?: string, no_wa?: ?string}  $data
     */
    public function update(PengantarSampel $pengantarSampel, array $data): PengantarSampel
    {
        DB::transaction(function () use ($pengantarSampel, $data) {
            $userUpdates = Arr::only($data, ['name', 'email']);
            if ($userUpdates !== []) {
                $pengantarSampel->user->update($userUpdates);
            }

            $updates = Arr::only($data, ['no_hp', 'no_wa']);
            if ($updates !== []) {
                $pengantarSampel->update($updates);
            }
        });

        return $pengantarSampel->fresh(['user', 'puskesmas']);
    }

    /**
     * Mirror persis TenagaKesehatanService::delete(). Fase A belum punya tabel riwayat
     * pengiriman sampel (baru datang di Fase C) -- begitu ada, tambahkan pengecekan
     * `pengirimanSampel()->exists()` di sini sebelum boleh hapus permanen, mirror gerbang
     * riwayat penugasan TenagaKesehatanService::delete().
     */
    public function delete(PengantarSampel $pengantarSampel): void
    {
        DB::transaction(function () use ($pengantarSampel) {
            $user = $pengantarSampel->user;
            $pengantarSampel->delete();

            if ($user && ! $user->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis']) && ! $user->kader()->exists() && ! $user->tenagaKesehatan()->exists()) {
                $user->delete();
            } else {
                $user?->removeRole('pengantar_sampel');
            }
        });
    }

    /**
     * @return Builder<PengantarSampel>
     */
    public function scopedQuery(User $user): Builder
    {
        if (DataScope::isFullAccess($user)) {
            return PengantarSampel::query();
        }

        if ($user->puskesmas_id === null) {
            return PengantarSampel::query()->whereRaw('1 = 0');
        }

        return PengantarSampel::query()->where('puskesmas_id', $user->puskesmas_id);
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

        if ($registrant->puskesmas_id === null) {
            throw ValidationException::withMessages([
                'puskesmas_id' => ['Akun Anda belum di-assign ke puskesmas mana pun.'],
            ]);
        }

        return $registrant->puskesmas_id;
    }
}
