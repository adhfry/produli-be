<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PengirimanSampel\ConfirmArrivalRequest;
use App\Http\Resources\PengirimanSampelResource;
use App\Models\PengirimanSampel;
use App\Services\PengirimanSampel\PengirimanSampelLokasiService;
use App\Services\PengirimanSampel\PengirimanSampelService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fase C, sisi KURIR (mobile /app) -- lihat docblock PengirimanSampelPolicy::isAssignedCourier()
 * untuk kenapa gating di sini LEBIH KETAT dari sharesPuskesmas() biasa (khusus kurir yang
 * bersangkutan sendiri, staf sepuskesmas lain tidak boleh startOtw/confirmArrival atas nama
 * kurir).
 */
class PengirimanSampelKurirController extends Controller
{
    public function __construct(
        private readonly PengirimanSampelService $service,
        private readonly PengirimanSampelLokasiService $lokasiService,
    ) {}

    /**
     * Daftar tugas kurir ini sendiri -- yang masih aktif (ditugaskan/otw) diutamakan, tapi
     * riwayat tiba/dikonfirmasi juga ikut supaya kurir bisa lihat riwayat pengirimannya.
     */
    public function myAssignments(Request $request): JsonResponse
    {
        $pengantarSampel = $request->user()->pengantarSampel;

        if ($pengantarSampel === null) {
            return ApiResponse::success(['items' => []]);
        }

        // Urutan prioritas via CASE WHEN (portabel MySQL/SQLite) -- BUKAN FIELD() yang MySQL-only,
        // supaya test suite (sqlite in-memory) tetap jalan sama seperti produksi.
        $statusPriority = "CASE status
            WHEN 'otw' THEN 1
            WHEN 'ditugaskan' THEN 2
            WHEN 'tiba_labkesda' THEN 3
            WHEN 'dikonfirmasi_labkesda' THEN 4
            ELSE 5 END";

        $batches = PengirimanSampel::query()
            ->where('pengantar_sampel_id', $pengantarSampel->id)
            ->whereIn('status', ['ditugaskan', 'otw', 'tiba_labkesda', 'dikonfirmasi_labkesda'])
            ->with(['puskesmas', 'pasien'])
            ->withCount('pasien')
            ->orderByRaw($statusPriority)
            ->orderByDesc('ditugaskan_at')
            ->get();

        return ApiResponse::success(['items' => PengirimanSampelResource::collection($batches)]);
    }

    public function startOtw(Request $request, PengirimanSampel $pengirimanSampel): JsonResponse
    {
        $this->authorize('isAssignedCourier', $pengirimanSampel);

        $updated = $this->service->startOtw($pengirimanSampel);

        return ApiResponse::success(new PengirimanSampelResource($updated), 'Perjalanan dimulai, lokasi Anda akan terlihat oleh Labkesda dan puskesmas.');
    }

    public function heartbeat(Request $request, PengirimanSampel $pengirimanSampel): JsonResponse
    {
        $this->authorize('isAssignedCourier', $pengirimanSampel);

        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->lokasiService->recordHeartbeat($pengirimanSampel, (float) $validated['latitude'], (float) $validated['longitude'], isset($validated['accuracy']) ? (float) $validated['accuracy'] : null);

        return ApiResponse::success(null);
    }

    public function confirmArrival(ConfirmArrivalRequest $request, PengirimanSampel $pengirimanSampel): JsonResponse
    {
        $this->authorize('isAssignedCourier', $pengirimanSampel);

        $validated = $request->validated();

        $updated = $this->service->confirmArrival(
            $pengirimanSampel,
            $request->file('photo'),
            (float) $validated['latitude'],
            (float) $validated['longitude'],
            isset($validated['gps_accuracy_meters']) ? (float) $validated['gps_accuracy_meters'] : null,
        );

        return ApiResponse::success(new PengirimanSampelResource($updated), 'Sampel berhasil dikonfirmasi tiba di Labkesda Sumenep.');
    }
}
