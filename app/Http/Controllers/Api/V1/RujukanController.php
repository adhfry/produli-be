<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Visit\ConfirmRujukanRequest;
use App\Http\Resources\RujukanResource;
use App\Models\VisitReport;
use App\Services\Visit\RujukanService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RujukanController extends Controller
{
    public function __construct(
        private readonly RujukanService $service,
    ) {}

    /**
     * List rujukan (Fase 3, docs plan) -- admin_puskesmas/pj_prolanis: rujukan dari kader/nakes
     * DI PUSKESMASNYA sendiri, super_admin: semua. Dipoll frontend tiap 15 detik (bukan
     * push/websocket) -- lihat app/pages/dashboard/rujukan/index.vue.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAnyRujukan', VisitReport::class);

        $paginator = $this->service->scopedQuery($request->user())
            ->with(['assignment.patient', 'assignment.kader.user', 'assignment.kader.puskesmas', 'assignment.tenagaKesehatan.user', 'assignment.tenagaKesehatan.puskesmas'])
            ->when(
                $request->filled('rujukan_status'),
                fn ($query) => $query->where('rujukan_status', $request->string('rujukan_status'))
            )
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success([
            'items' => RujukanResource::collection($paginator),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * admin_puskesmas/pj_prolanis mengonfirmasi kedatangan pasien rujukan, atau membatalkannya.
     */
    public function konfirmasi(ConfirmRujukanRequest $request, VisitReport $visitReport): JsonResponse
    {
        $this->authorize('confirmRujukan', $visitReport);

        $report = $this->service->konfirmasi($visitReport, $request->validated('status'));
        $report->load(['assignment.patient', 'assignment.kader.user', 'assignment.kader.puskesmas', 'assignment.tenagaKesehatan.user', 'assignment.tenagaKesehatan.puskesmas']);

        return ApiResponse::success(new RujukanResource($report), 'Status rujukan berhasil diperbarui');
    }
}
