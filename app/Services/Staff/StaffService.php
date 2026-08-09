<?php

namespace App\Services\Staff;

use App\Models\User;
use App\Services\Auth\AccountActivationService;
use App\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registrasi staf (admin_puskesmas/pj_prolanis) baru (docs/planning/02 §7, §11) -- pola
 * find-or-create User by email SAMA seperti KaderService::register(): User baru dikirimi
 * email aktivasi, User existing di-skip (cuma ditambahkan role barunya, dual-role tetap
 * didukung), tidak dikirimi ulang email aktivasi.
 *
 * super_admin: boleh daftarkan admin_puskesmas ATAU pj_prolanis, untuk puskesmas mana pun
 * (wajib isi puskesmas_id sendiri -- super_admin tidak punya puskesmas_id sendiri untuk
 * dipaksakan).
 * admin_puskesmas: HANYA boleh daftarkan pj_prolanis (bukan sesama admin_puskesmas -- itu tetap
 * wewenang super_admin, docs/planning/02 §11), dipaksa ke puskesmas_id miliknya sendiri,
 * abaikan input klien kalau ada (defense in depth, sama pola seperti KaderService).
 */
class StaffService
{
    public function __construct(private readonly AccountActivationService $accountActivationService) {}

    /**
     * @param  array{name: string, email: string, no_hp: string, puskesmas_id?: ?int, role: string}  $data
     */
    public function register(User $registrant, array $data): User
    {
        $this->ensureRoleAllowed($registrant, $data['role']);
        // super_admin TIDAK terikat 1 puskesmas (puskesmas_id selalu null utk role ini) -- beda
        // dari admin_puskesmas/pj_prolanis yang WAJIB resolvePuskesmasId() (dipaksa sendiri atau
        // wajib isi manual utk super_admin yang mendaftarkan).
        $puskesmasId = $data['role'] === 'super_admin' ? null : $this->resolvePuskesmasId($registrant, $data);

        $user = null;

        DB::transaction(function () use ($data, $puskesmasId, &$user) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => null,
                    'no_hp' => $data['no_hp'],
                    'puskesmas_id' => $puskesmasId,
                ],
            );

            if (! $user->hasRole($data['role'])) {
                $user->assignRole($data['role']);
            }
        });

        if ($user->wasRecentlyCreated) {
            $this->accountActivationService->inviteNewUser($user, $registrant);
        }

        return $user;
    }

    /**
     * List staf (super_admin + admin_puskesmas + pj_prolanis) ter-scope role -- pola sama
     * seperti KaderService::scopedQuery(): super_admin (full-access) lihat semua TERMASUK
     * sesama super_admin, admin_puskesmas/pj_prolanis cuma puskesmasnya sendiri (docs/planning/
     * 02 §7/§11) -- akun super_admin (puskesmas_id selalu null) otomatis tidak pernah cocok
     * filter puskesmas_id di scope itu, jadi aman ikut di query dasar tanpa cek tambahan.
     *
     * @return Builder<User>
     */
    public function scopedQuery(User $user): Builder
    {
        $query = User::role(['super_admin', 'admin_puskesmas', 'pj_prolanis']);

        if (DataScope::isFullAccess($user)) {
            return $query;
        }

        if ($user->puskesmas_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('puskesmas_id', $user->puskesmas_id);
    }

    private function ensureRoleAllowed(User $registrant, string $role): void
    {
        if (DataScope::isFullAccess($registrant)) {
            return; // super_admin boleh daftarkan role apa pun (super_admin/admin_puskesmas/pj_prolanis).
        }

        if ($role !== 'pj_prolanis') {
            throw ValidationException::withMessages([
                'role' => ['admin_puskesmas cuma boleh mendaftarkan pj_prolanis, bukan sesama admin_puskesmas.'],
            ]);
        }
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

        // admin_puskesmas: dipaksa ke puskesmas sendiri, abaikan input klien kalau ada.
        if ($registrant->puskesmas_id === null) {
            throw ValidationException::withMessages([
                'puskesmas_id' => ['Akun Anda belum di-assign ke puskesmas mana pun.'],
            ]);
        }

        return $registrant->puskesmas_id;
    }
}
