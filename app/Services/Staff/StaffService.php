<?php

namespace App\Services\Staff;

use App\Models\User;
use App\Models\VisitAssignment;
use App\Services\Auth\AccountActivationService;
use App\Services\Auth\AuthTokenService;
use App\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
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
    public function __construct(
        private readonly AccountActivationService $accountActivationService,
        private readonly AuthTokenService $authTokenService,
    ) {}

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
     * @param  array{name?: string, email?: string, no_hp?: string}  $data
     */
    public function update(User $actor, User $target, array $data): User
    {
        $this->ensureCanManage($actor, $target);

        $target->update(Arr::only($data, ['name', 'email', 'no_hp']));

        return $target->fresh();
    }

    /**
     * Hapus PERMANEN staf -- BEDA dari setActive(false) (nonaktifkan, riwayat tetap tersimpan).
     * SEKARANG diblokir kalau $target pernah jadi assigned_by di visit_assignments (riwayat
     * "siapa yang menugaskan" harus tetap ada, sama alasan dengan KaderService::delete()
     * menolak kader yang punya riwayat kunjungan) -- sebelumnya TIDAK dicek sama sekali, hard
     * delete staf lama diam-diam melepas jejak audit itu (visit_assignments.assigned_by
     * nullOnDelete() di migration, jadi tidak error di DB, cuma datanya hilang tanpa jejak).
     * Kalau sudah punya riwayat, nonaktifkan saja lewat setActive().
     */
    public function delete(User $actor, User $target): void
    {
        if ($actor->id === $target->id) {
            throw ValidationException::withMessages([
                'staff' => ['Anda tidak bisa menghapus akun Anda sendiri.'],
            ]);
        }

        $this->ensureCanManage($actor, $target);

        if ($target->hasRole('super_admin') && User::role('super_admin')->count() <= 1) {
            throw ValidationException::withMessages([
                'staff' => ['Tidak bisa menghapus satu-satunya akun super_admin yang tersisa.'],
            ]);
        }

        if (VisitAssignment::where('assigned_by', $target->id)->exists()) {
            throw ValidationException::withMessages([
                'staff' => ['Staf ini sudah pernah menugaskan kunjungan, tidak bisa dihapus permanen -- nonaktifkan saja supaya riwayatnya tetap tersimpan.'],
            ]);
        }

        DB::transaction(function () use ($target) {
            $target->removeRole('super_admin');
            $target->removeRole('admin_puskesmas');
            $target->removeRole('pj_prolanis');

            if (! $target->hasAnyRole(['kader', 'tenaga_kesehatan'])) {
                $target->delete();
            }
        });
    }

    /**
     * Aktifkan/nonaktifkan staf (pola sama persis KaderService::setActive()/TenagaKesehatanService::
     * setActive()) -- BEDA dari delete(): riwayat assigned_by di visit_assignments TETAP UTUH,
     * cuma menghentikan kemampuan staf ini bertindak lagi (dipakai saat staf keluar/pindah tapi
     * sudah punya riwayat, jadi tidak bisa dihapus permanen lewat delete() di atas).
     */
    public function setActive(User $actor, User $target, bool $active): User
    {
        if ($actor->id === $target->id && ! $active) {
            throw ValidationException::withMessages([
                'staff' => ['Anda tidak bisa menonaktifkan akun Anda sendiri.'],
            ]);
        }

        $this->ensureCanManage($actor, $target);

        if (! $active && $target->hasRole('super_admin') && User::role('super_admin')->where('status_aktif', true)->count() <= 1) {
            throw ValidationException::withMessages([
                'staff' => ['Tidak bisa menonaktifkan satu-satunya akun super_admin yang masih aktif.'],
            ]);
        }

        $target->update(['status_aktif' => $active]);

        // Nonaktifkan sekarang benar-benar mencabut akses (keputusan Kepala Dinas -- staf
        // punya akses dashboard/data pasien lebih luas dari kader lapangan, beda dari
        // KaderService::setActive() yang cuma menghentikan penugasan BARU) -- login() dan
        // AuthTokenService::refresh() sama-sama menolak user dengan status_aktif=false, TAPI
        // access token Sanctum yang SUDAH terbit (berlaku sampai config('sanctum.expiration')
        // menit) tetap valid sampai request refresh berikutnya kalau tidak di-revoke di sini.
        // Revoke semua refresh token supaya sesi yang sedang berjalan mati secepatnya, bukan
        // menunggu access token itu kedaluwarsa sendiri.
        if (! $active) {
            $this->authTokenService->revokeAllForUser($target->id);
        }

        return $target->fresh();
    }

    /**
     * Gerbang sama seperti ensureRoleAllowed()/resolvePuskesmasId() -- super_admin bebas kelola
     * siapa pun, admin_puskesmas cuma boleh kelola pj_prolanis di puskesmasnya sendiri (bukan
     * sesama admin_puskesmas/super_admin), dipakai bersama oleh update() dan delete().
     */
    private function ensureCanManage(User $actor, User $target): void
    {
        if (DataScope::isFullAccess($actor)) {
            return;
        }

        if (! $target->hasRole('pj_prolanis') || $target->hasAnyRole(['admin_puskesmas', 'super_admin'])) {
            throw ValidationException::withMessages([
                'staff' => ['Anda cuma boleh mengelola PJ Prolanis di puskesmas sendiri.'],
            ]);
        }

        if ($actor->puskesmas_id === null || $target->puskesmas_id !== $actor->puskesmas_id) {
            throw ValidationException::withMessages([
                'staff' => ['Staf ini bukan bagian dari puskesmas Anda.'],
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
