<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Puskesmas\UpdatePuskesmasRequest;
use App\Http\Resources\PuskesmasResource;
use App\Models\Puskesmas;
use App\Services\Puskesmas\PuskesmasGeocodingService;
use App\Services\Puskesmas\PuskesmasService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Data Instansi (Puskesmas) -- docs/planning/02 §15. Tidak ada store()/destroy() dengan
 * sengaja -- penambahan/penutupan puskesmas tetap lewat migration/seeder terkontrol
 * (PuskesmasSeeder), bukan tombol UI.
 */
class PuskesmasController extends Controller
{
    public function __construct(
        private readonly PuskesmasService $service,
        private readonly PuskesmasGeocodingService $geocodingService,
    ) {}

    /**
     * Semua role login, tanpa scope (docs/planning/02 §15).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Puskesmas::class);

        $paginator = $this->service->paginate($request->integer('per_page', 20));

        return ApiResponse::success([
            'items' => PuskesmasResource::collection($paginator),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(Puskesmas $puskesmas): JsonResponse
    {
        $this->authorize('view', $puskesmas);

        return ApiResponse::success(new PuskesmasResource($puskesmas->load('kecamatan')));
    }

    /**
     * admin_puskesmas cuma puskesmasnya sendiri, super_admin bebas (PuskesmasPolicy::update).
     * Field yang bisa diubah cuma alamat/no_telp/no_wa/latitude/longitude/deskripsi.
     */
    public function update(UpdatePuskesmasRequest $request, Puskesmas $puskesmas): JsonResponse
    {
        $this->authorize('update', $puskesmas);

        $updated = $this->service->update($puskesmas, $request->validated());

        return ApiResponse::success(new PuskesmasResource($updated), 'Data instansi berhasil diperbarui');
    }

    /**
     * Cari-otomatis koordinat SELURUH puskesmas se-Kabupaten Sumenep via Nominatim (permintaan
     * Bu Kadis, super_admin only) -- admin_puskesmas tetap bisa mengoreksi manual lewat update()
     * di atas kalau hasilnya kurang presisi. Sinkron (bukan queue job) -- ~31 puskesmas x ~1,1
     * detik/request (rate limit Nominatim) selesai dalam <1 menit, cukup cepat untuk ditunggu
     * langsung dari 1 klik tombol tanpa perlu polling status terpisah.
     */
    public function geocodeAll(Request $request): JsonResponse
    {
        $this->authorize('geocodeAll', Puskesmas::class);

        $result = $this->geocodingService->geocodeAll($request->boolean('overwrite_existing'));

        return ApiResponse::success(
            $result,
            "Pencarian koordinat selesai: {$result['updated']} berhasil, {$result['failed']} gagal, {$result['skipped']} dilewati (sudah punya koordinat)."
        );
    }
}
