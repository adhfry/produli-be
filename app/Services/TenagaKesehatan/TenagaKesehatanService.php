<?php

namespace App\Services\TenagaKesehatan;

use App\Models\TenagaKesehatan;
use App\Models\User;
use App\Services\Auth\AccountActivationService;
use App\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registrasi tenaga_kesehatan baru (revisi Bu Kadis) -- mirror persis KaderService (lihat
 * docblock di sana), scoping puskesmas identik. Dibedakan dari kader karena tugasnya beda
 * (pemeriksaan lanjutan, bukan pendampingan minum obat), tapi mekanisme registrasi/aktivasi
 * akunnya sama persis.
 */
class TenagaKesehatanService
{
    public function __construct(private readonly AccountActivationService $accountActivationService) {}

    /**
     * @param  array{name: string, email: string, no_hp: string, no_wa?: ?string, alamat?: ?string,
     *     gender?: ?string, tgl_lahir?: ?string, puskesmas_id?: ?int, pj_id?: ?int}  $data
     */
    public function register(User $registrant, array $data): TenagaKesehatan
    {
        $puskesmasId = $this->resolvePuskesmasId($registrant, $data);

        $user = null;

        $tenagaKesehatan = DB::transaction(function () use ($data, $puskesmasId, &$user) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => null,
                    'puskesmas_id' => $puskesmasId,
                ],
            );

            if ($user->tenagaKesehatan()->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['User dengan email ini sudah terdaftar sebagai tenaga kesehatan.'],
                ]);
            }

            if (! $user->hasRole('tenaga_kesehatan')) {
                $user->assignRole('tenaga_kesehatan');
            }

            return TenagaKesehatan::create([
                'user_id' => $user->id,
                'pj_id' => $data['pj_id'] ?? null,
                'puskesmas_id' => $puskesmasId,
                'status_aktif' => true,
                'no_hp' => $data['no_hp'],
                'no_wa' => $data['no_wa'] ?? null,
                'alamat' => $data['alamat'] ?? null,
                'gender' => $data['gender'] ?? null,
                'tgl_lahir' => $data['tgl_lahir'] ?? null,
            ]);
        });

        if ($user->wasRecentlyCreated) {
            $this->accountActivationService->inviteNewUser($user, $registrant);
        }

        return $tenagaKesehatan;
    }

    public function setActive(TenagaKesehatan $tenagaKesehatan, bool $active): TenagaKesehatan
    {
        $tenagaKesehatan->update(['status_aktif' => $active]);

        return $tenagaKesehatan->fresh();
    }

    /**
     * @return Builder<TenagaKesehatan>
     */
    public function scopedQuery(User $user): Builder
    {
        if (DataScope::isFullAccess($user)) {
            return TenagaKesehatan::query();
        }

        if ($user->puskesmas_id === null) {
            return TenagaKesehatan::query()->whereRaw('1 = 0');
        }

        return TenagaKesehatan::query()->where('puskesmas_id', $user->puskesmas_id);
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
