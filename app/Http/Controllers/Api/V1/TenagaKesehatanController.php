<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TenagaKesehatan\RegisterTenagaKesehatanRequest;
use App\Http\Resources\TenagaKesehatanResource;
use App\Models\TenagaKesehatan;
use App\Services\TenagaKesehatan\TenagaKesehatanService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
