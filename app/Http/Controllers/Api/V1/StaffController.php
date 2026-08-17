<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\RegisterStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use App\Models\User;
use App\Services\Auth\AdminPasswordResetService;
use App\Services\Staff\StaffService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function __construct(private readonly StaffService $service) {}

    /**
     * List staf (admin_puskesmas + pj_prolanis), ter-scope sama seperti store() (docs/planning/02
     * §7/§11) -- super_admin lihat semua, admin_puskesmas/pj_prolanis cuma puskesmasnya sendiri
     * (StaffService::scopedQuery generik lewat puskesmas_id, tidak bercabang per role). Bukan
     * Policy resource (User bukan model domain PRODULI sendiri), sama seperti store().
     */
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis'])) {
            throw new AuthorizationException('Hanya super_admin, admin_puskesmas, atau pj_prolanis yang bisa melihat daftar staf.');
        }

        $paginator = $this->service->scopedQuery($request->user())
            ->with('puskesmas')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success([
            'items' => StaffResource::collection($paginator),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * super_admin mendaftarkan admin_puskesmas/pj_prolanis baru; admin_puskesmas cuma boleh
     * mendaftarkan pj_prolanis untuk puskesmasnya sendiri (docs/planning/02 §11 -- koreksi dari
     * draf resmi yang tadinya keliru membatasi endpoint ini cuma super_admin). Pembatasan
     * spesifik "admin_puskesmas cuma boleh pj_prolanis, dipaksa puskesmas sendiri" ditegakkan di
     * StaffService, bukan di sini -- gerbang di controller cuma "role apa saja yang boleh
     * mencoba akses endpoint ini sama sekali". Bukan Policy resource (User bukan model domain
     * PRODULI sendiri).
     */
    public function store(RegisterStaffRequest $request): JsonResponse
    {
        if (! $request->user()->hasAnyRole(['super_admin', 'admin_puskesmas'])) {
            throw new AuthorizationException('Hanya super_admin atau admin_puskesmas yang bisa mendaftarkan staf.');
        }

        $user = $this->service->register($request->user(), $request->validated());
        $user->load('puskesmas');

        return ApiResponse::success(new StaffResource($user), 'Staf berhasil didaftarkan', 201);
    }

    /**
     * Update data staf -- gerbang sama dengan store() (super_admin/admin_puskesmas), pembatasan
     * spesifik ("admin_puskesmas cuma boleh kelola pj_prolanis puskesmas sendiri") ditegakkan di
     * StaffService::ensureCanManage(), bukan di sini.
     */
    public function update(UpdateStaffRequest $request, User $user): JsonResponse
    {
        if (! $request->user()->hasAnyRole(['super_admin', 'admin_puskesmas'])) {
            throw new AuthorizationException('Hanya super_admin atau admin_puskesmas yang bisa mengubah data staf.');
        }

        $updated = $this->service->update($request->user(), $user, $request->validated());
        $updated->load('puskesmas');

        return ApiResponse::success(new StaffResource($updated), 'Data staf berhasil diperbarui');
    }

    /**
     * Hapus PERMANEN staf -- gerbang sama dengan store()/update(). Tidak bisa hapus diri sendiri,
     * satu-satunya super_admin yang tersisa, atau staf yang sudah punya riwayat penugasan
     * (StaffService::delete() -- pesan errornya sendiri sudah mengarahkan ke setStatus()).
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if (! $request->user()->hasAnyRole(['super_admin', 'admin_puskesmas'])) {
            throw new AuthorizationException('Hanya super_admin atau admin_puskesmas yang bisa menghapus staf.');
        }

        $this->service->delete($request->user(), $user);

        return ApiResponse::success(null, 'Staf berhasil dihapus');
    }

    /**
     * Aktifkan/nonaktifkan staf -- gerbang sama dengan update()/destroy(). Dipakai saat staf
     * keluar/pindah tugas tapi sudah punya riwayat penugasan (destroy() akan menolak kasus itu),
     * pola sama persis KaderController::setStatus()/TenagaKesehatanController::setStatus().
     */
    public function setStatus(Request $request, User $user): JsonResponse
    {
        if (! $request->user()->hasAnyRole(['super_admin', 'admin_puskesmas'])) {
            throw new AuthorizationException('Hanya super_admin atau admin_puskesmas yang bisa mengubah status staf.');
        }

        $validated = $request->validate([
            'status_aktif' => ['required', 'boolean'],
        ]);

        $updated = $this->service->setActive($request->user(), $user, $validated['status_aktif']);
        $updated->load('puskesmas');

        $message = $validated['status_aktif'] ? 'Staf berhasil diaktifkan' : 'Staf berhasil dinonaktifkan';

        return ApiResponse::success(new StaffResource($updated), $message);
    }

    /**
     * Reset password staf, dipicu ADMINISTRATOR (super_admin saja -- bukan admin_puskesmas,
     * walau admin_puskesmas boleh update/delete pj_prolanis di puskesmasnya) -- mirror persis
     * KaderController::resetPassword().
     */
    public function resetPassword(Request $request, User $user, AdminPasswordResetService $resetService): JsonResponse
    {
        if (! $request->user()->hasRole('super_admin')) {
            throw new AuthorizationException('Hanya super_admin yang bisa mereset password.');
        }

        $resetService->reset($user, $request->user());

        return ApiResponse::success(null, "Password berhasil direset, email berisi password baru sudah dikirim ke {$user->email}.");
    }
}
