<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CareAssignment\AssignTenagaKesehatanRequest;
use App\Http\Requests\CareAssignment\CreateAdhocVisitRequest;
use App\Models\CareAssignment;
use App\Models\Kader;
use App\Models\PatientsCache;
use App\Models\TenagaKesehatan;
use App\Services\Visit\CareAssignmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

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
