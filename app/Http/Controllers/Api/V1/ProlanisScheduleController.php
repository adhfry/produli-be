<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Prolanis\RescheduleProlanisScheduleRequest;
use App\Http\Requests\Prolanis\UpdateProlanisScheduleStatusRequest;
use App\Http\Resources\ProlanisScheduleResource;
use App\Models\ProlanisSchedule;
use App\Services\Prolanis\ProlanisScheduleService;
use App\Support\ApiResponse;
use App\Support\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Halaman /dashboard/jadwal-prolanis (permintaan user) -- kalender jadwal kegiatan Prolanis per
 * pasien, dihitung otomatis dari tanggal lab terbaru (ProlanisScheduleService::generateSchedules()).
 */
class ProlanisScheduleController extends Controller
{
    public function __construct(private readonly ProlanisScheduleService $service) {}

    /**
     * ?date_from=&date_to= (rentang tampilan kalender) + ?puskesmas_id= (KHUSUS super_admin,
     * diabaikan untuk role lain sama seperti filter serupa di PatientController::index()).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ProlanisSchedule::class);

        $query = $this->service->scopedQuery($request->user())
            ->when(
                $request->filled('date_from'),
                fn ($q) => $q->whereDate('scheduled_date', '>=', $request->string('date_from'))
            )
            ->when(
                $request->filled('date_to'),
                fn ($q) => $q->whereDate('scheduled_date', '<=', $request->string('date_to'))
            )
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->string('status'))
            )
            ->when(
                DataScope::isFullAccess($request->user()) && $request->filled('puskesmas_id'),
                fn ($q) => $q->where('puskesmas_id', $request->integer('puskesmas_id'))
            )
            ->orderBy('scheduled_date');

        $schedules = $query->limit(1000)->get();

        return ApiResponse::success(ProlanisScheduleResource::collection($schedules));
    }

    public function reschedule(RescheduleProlanisScheduleRequest $request, ProlanisSchedule $prolanisSchedule): JsonResponse
    {
        $this->authorize('update', $prolanisSchedule);

        $schedule = $this->service->reschedule($prolanisSchedule, $request->validated('scheduled_date'), $request->user());
        $schedule->load(['patient', 'puskesmas', 'updatedBy']);

        return ApiResponse::success(new ProlanisScheduleResource($schedule), 'Jadwal berhasil diperbarui');
    }

    public function updateStatus(UpdateProlanisScheduleStatusRequest $request, ProlanisSchedule $prolanisSchedule): JsonResponse
    {
        $this->authorize('update', $prolanisSchedule);

        $schedule = $this->service->updateStatus($prolanisSchedule, $request->validated('status'), $request->user());
        $schedule->load(['patient', 'puskesmas', 'updatedBy']);

        return ApiResponse::success(new ProlanisScheduleResource($schedule), 'Status jadwal berhasil diperbarui');
    }
}
