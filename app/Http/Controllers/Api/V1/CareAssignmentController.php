<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CareAssignment\AssignTenagaKesehatanRequest;
use App\Http\Requests\CareAssignment\CreateAdhocVisitRequest;
use App\Http\Requests\CareAssignment\RescheduleCareAssignmentRequest;
use App\Http\Resources\CareAssignmentResource;
use App\Models\CareAssignment;
use App\Models\Kader;
use App\Models\PatientsCache;
use App\Models\TenagaKesehatan;
use App\Services\Visit\CareAssignmentCadenceService;
use App\Services\Visit\CareAssignmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Rencana kunjungan berulang tenaga_kesehatan (revisi Bu Kadis) -- assign kader sudah lewat
 * VisitAssignmentController (VisitAssignmentService::assign() dihook CareAssignmentService::
 * ensureKaderPlan() di sana, lihat controller itu), controller ini KHUSUS jalur
 * tenaga_kesehatan yang tidak punya assign satu-kali existing untuk dinaiki.
 */
class CareAssignmentController extends Controller
{
    public function __construct(private readonly CareAssignmentService $service) {}

    /**
     * PJ Prolanis/admin_puskesmas/super_admin menugaskan tenaga_kesehatan ke pasien -- membuat
     * rencana kunjungan berulang DAN kunjungan pertamanya sekaligus.
     */
    public function store(AssignTenagaKesehatanRequest $request): JsonResponse
    {
        $this->authorize('create', CareAssignment::class);

        $patient = PatientsCache::findOrFail($request->validated('patient_id'));
        $tenagaKesehatan = TenagaKesehatan::findOrFail($request->validated('tenaga_kesehatan_id'));
        $kader = $request->validated('kader_id') !== null
            ? Kader::findOrFail($request->validated('kader_id'))
            : null;

        $plan = $this->service->assignTenagaKesehatan(
            $patient,
            $tenagaKesehatan,
            $request->user(),
            $request->validated('scheduled_date'),
            $kader,
        );
        $plan->load(['patient', 'tenagaKesehatan.user', 'assignedBy']);

        return ApiResponse::success($this->formatPlan($plan), 'Tenaga kesehatan berhasil ditugaskan', 201);
    }

    /**
     * Kunjungan tambahan mendesak (revisi Bu Kadis) -- pasien butuh pemeriksaan intensif di
     * luar jadwal rutin. Reset hitungan kunjungan rutin berikutnya ke titik ini (lihat docblock
     * CareAssignmentService::createAdhocVisit()).
     */
    public function createAdhocVisit(CreateAdhocVisitRequest $request, CareAssignment $careAssignment): JsonResponse
    {
        $this->authorize('createAdhocVisit', $careAssignment);

        $visit = $this->service->createAdhocVisit(
            $careAssignment,
            $request->user(),
            $request->validated('scheduled_date'),
        );

        return ApiResponse::success([
            'id' => $visit->id,
            'scheduled_date' => $visit->scheduled_date->toDateString(),
            'status' => $visit->status,
            'visit_origin' => $visit->visit_origin,
        ], 'Kunjungan tambahan mendesak berhasil dijadwalkan', 201);
    }

    /**
     * Geser tanggal kunjungan berikutnya dari rencana kunjungan berulang yang masih aktif
     * (permintaan user, fitur "atur ulang jadwal") -- lihat docblock
     * CareAssignmentCadenceService::rescheduleTo() untuk cara kerja & guard-nya.
     */
    public function reschedule(RescheduleCareAssignmentRequest $request, CareAssignment $careAssignment, CareAssignmentCadenceService $cadence): JsonResponse
    {
        $this->authorize('reschedule', $careAssignment);

        $cadence->rescheduleTo($careAssignment, Carbon::parse($request->validated('next_date')));

        return ApiResponse::success(
            new CareAssignmentResource($careAssignment->fresh()),
            'Jadwal kunjungan berikutnya berhasil diatur ulang',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPlan(CareAssignment $plan): array
    {
        return [
            'id' => $plan->id,
            'worker_type' => $plan->worker_type,
            'status' => $plan->status,
            'last_triggered_at' => $plan->last_triggered_at?->toDateString(),
            'patient' => $plan->relationLoaded('patient') ? [
                'id' => $plan->patient->id,
                'nama' => $plan->patient->nama,
            ] : null,
            'tenaga_kesehatan' => $plan->relationLoaded('tenagaKesehatan') && $plan->tenagaKesehatan ? [
                'id' => $plan->tenagaKesehatan->id,
                'user' => ['name' => $plan->tenagaKesehatan->user?->name],
            ] : null,
        ];
    }
}
