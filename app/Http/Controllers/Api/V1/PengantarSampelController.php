<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PengantarSampel\RegisterPengantarSampelRequest;
use App\Http\Requests\PengantarSampel\UpdatePengantarSampelRequest;
use App\Http\Resources\PengantarSampelResource;
use App\Models\PengantarSampel;
use App\Services\Auth\AdminPasswordResetService;
use App\Services\PengantarSampel\PengantarSampelService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Mirror persis TenagaKesehatanController (list/store/setStatus/update/destroy/reset-password)
 * -- lihat docblock PengantarSampelService. Belum ada self-service /app profile di sini (Fase A):
 * halaman mobile kurir (/app/pengiriman/**) baru dibangun Fase C, dan role ini belum punya field
 * yang perlu di-self-service seperti tenaga_kesehatan/kader.
 */
class PengantarSampelController extends Controller
{
    public function __construct(private readonly PengantarSampelService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PengantarSampel::class);

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
            'items' => PengantarSampelResource::collection($paginator),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(RegisterPengantarSampelRequest $request): JsonResponse
    {
        $this->authorize('create', PengantarSampel::class);

        $pengantarSampel = $this->service->register($request->user(), $request->validated());
        $pengantarSampel->load(['user', 'puskesmas']);

        return ApiResponse::success(new PengantarSampelResource($pengantarSampel), 'Pengantar sampel berhasil didaftarkan', 201);
    }

    public function setStatus(Request $request, PengantarSampel $pengantarSampel): JsonResponse
    {
        $this->authorize('update', $pengantarSampel);

        $validated = $request->validate([
            'status_aktif' => ['required', 'boolean'],
        ]);

        $updated = $this->service->setActive($pengantarSampel, $validated['status_aktif']);
        $updated->load(['user', 'puskesmas']);

        $message = $validated['status_aktif'] ? 'Pengantar sampel berhasil diaktifkan' : 'Pengantar sampel berhasil dinonaktifkan';

        return ApiResponse::success(new PengantarSampelResource($updated), $message);
    }

    public function update(UpdatePengantarSampelRequest $request, PengantarSampel $pengantarSampel): JsonResponse
    {
        $this->authorize('update', $pengantarSampel);

        $updated = $this->service->update($pengantarSampel, $request->validated());

        return ApiResponse::success(new PengantarSampelResource($updated), 'Data pengantar sampel berhasil diperbarui');
    }

    public function destroy(PengantarSampel $pengantarSampel): JsonResponse
    {
        $this->authorize('delete', $pengantarSampel);

        $this->service->delete($pengantarSampel);

        return ApiResponse::success(null, 'Pengantar sampel berhasil dihapus');
    }

    /**
     * Reset password, dipicu ADMINISTRATOR (super_admin saja) -- mirror persis
     * TenagaKesehatanController::resetPassword().
     */
    public function resetPassword(Request $request, PengantarSampel $pengantarSampel, AdminPasswordResetService $resetService): JsonResponse
    {
        if (! $request->user()->hasRole('super_admin')) {
            throw new AuthorizationException('Hanya super_admin yang bisa mereset password.');
        }

        $pengantarSampel->loadMissing('user');

        if ($pengantarSampel->user === null) {
            throw ValidationException::withMessages([
                'pengantar_sampel' => ['Pengantar sampel ini tidak punya akun user yang valid.'],
            ]);
        }

        $resetService->reset($pengantarSampel->user, $request->user());

        return ApiResponse::success(null, "Password berhasil direset, email berisi password baru sudah dikirim ke {$pengantarSampel->user->email}.");
    }
}
