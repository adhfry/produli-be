<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TenagaKesehatan\RegisterTenagaKesehatanRequest;
use App\Http\Requests\TenagaKesehatan\UpdateTenagaKesehatanRequest;
use App\Http\Resources\TenagaKesehatanResource;
use App\Models\TenagaKesehatan;
use App\Services\Auth\AdminPasswordResetService;
use App\Services\TenagaKesehatan\TenagaKesehatanService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Mirror persis KaderController (list/store/setStatus) -- lihat docblock KaderService.
 * Self-service (profil sendiri) sengaja belum ada di fase ini (tenaga_kesehatan belum punya
 * mode mobile /app seperti kader, cuma dashboard staf) -- bisa menyusul kalau dibutuhkan.
 */
class TenagaKesehatanController extends Controller
{
    public function __construct(private readonly TenagaKesehatanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TenagaKesehatan::class);

        $paginator = $this->service->scopedQuery($request->user())
            ->with(['user', 'puskesmas'])
            ->when(
                $request->user()->hasRole('super_admin') && $request->filled('puskesmas_id'),
                fn ($query) => $query->where('puskesmas_id', $request->integer('puskesmas_id'))
            )
            ->when(
                $request->filled('status_aktif'),
                fn ($query) => $query->where('status_aktif', $request->boolean('status_aktif'))
            )
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success([
            'items' => TenagaKesehatanResource::collection($paginator),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(RegisterTenagaKesehatanRequest $request): JsonResponse
    {
        $this->authorize('create', TenagaKesehatan::class);

        $tenagaKesehatan = $this->service->register($request->user(), $request->validated());
        $tenagaKesehatan->load(['user', 'puskesmas']);

        return ApiResponse::success(new TenagaKesehatanResource($tenagaKesehatan), 'Tenaga kesehatan berhasil didaftarkan', 201);
    }

    public function setStatus(Request $request, TenagaKesehatan $tenagaKesehatan): JsonResponse
    {
        $this->authorize('update', $tenagaKesehatan);

        $validated = $request->validate([
            'status_aktif' => ['required', 'boolean'],
        ]);

        $updated = $this->service->setActive($tenagaKesehatan, $validated['status_aktif']);
        $updated->load(['user', 'puskesmas']);

        $message = $validated['status_aktif'] ? 'Tenaga kesehatan berhasil diaktifkan' : 'Tenaga kesehatan berhasil dinonaktifkan';

        return ApiResponse::success(new TenagaKesehatanResource($updated), $message);
    }

    public function update(UpdateTenagaKesehatanRequest $request, TenagaKesehatan $tenagaKesehatan): JsonResponse
    {
        $this->authorize('update', $tenagaKesehatan);

        $updated = $this->service->update($request->user(), $tenagaKesehatan, $request->validated());

        return ApiResponse::success(new TenagaKesehatanResource($updated), 'Data tenaga kesehatan berhasil diperbarui');
    }

    public function destroy(TenagaKesehatan $tenagaKesehatan): JsonResponse
    {
        $this->authorize('delete', $tenagaKesehatan);

        $this->service->delete($tenagaKesehatan);

        return ApiResponse::success(null, 'Tenaga kesehatan berhasil dihapus');
    }

    /**
     * Reset password, dipicu ADMINISTRATOR (super_admin saja) -- mirror persis
     * KaderController::resetPassword().
     */
    public function resetPassword(Request $request, TenagaKesehatan $tenagaKesehatan, AdminPasswordResetService $resetService): JsonResponse
    {
        if (! $request->user()->hasRole('super_admin')) {
            throw new AuthorizationException('Hanya super_admin yang bisa mereset password.');
        }

        $tenagaKesehatan->loadMissing('user');

        if ($tenagaKesehatan->user === null) {
            throw ValidationException::withMessages([
                'tenaga_kesehatan' => ['Tenaga kesehatan ini tidak punya akun user yang valid.'],
            ]);
        }

        $resetService->reset($tenagaKesehatan->user, $request->user());

        return ApiResponse::success(null, "Password berhasil direset, email berisi password baru sudah dikirim ke {$tenagaKesehatan->user->email}.");
    }
}
