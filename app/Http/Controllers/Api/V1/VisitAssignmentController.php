<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Visit\BulkCreateVisitAssignmentRequest;
use App\Http\Requests\Visit\CreateVisitAssignmentRequest;
use App\Http\Resources\VisitAssignmentResource;
use App\Models\Kader;
use App\Models\PatientsCache;
use App\Models\VisitAssignment;
use App\Services\Visit\CareAssignmentService;
use App\Services\Visit\VisitAssignmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisitAssignmentController extends Controller
{
    public function __construct(
        private readonly VisitAssignmentService $service,
        private readonly CareAssignmentService $careAssignmentService,
    ) {}

    /**
     * List assignment (docs/planning/02 §7) -- kader murni: tugasnya sendiri saja,
     * admin_puskesmas/pj_prolanis: semua di puskesmasnya, super_admin: semua.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', VisitAssignment::class);

        $paginator = $this->service->scopedQuery($request->user())
            ->with([
                'patient', 'kader.user', 'tenagaKesehatan.user', 'assignedBy', 'puskesmasSnapshot', 'companions.kader.user',
                'latestReport.pjReviewedBy', 'latestReport.validatedBy', 'latestReport.attendees.kader.user',
            ])
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status'))
            )
            ->orderBy('scheduled_date')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success([
            'items' => VisitAssignmentResource::collection($paginator),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Monitoring kunjungan (revisi Bu Kadis -- summary strip dashboard/kunjungan/index.vue) --
     * berapa belum/sedang-proses/selesai/tenggat-lewat, dan siapa mengunjungi desa mana.
     * viewAny (BUKAN ability terpisah) -- sama gate dengan index(), cuma ringkasan dari data
     * yang sama, bukan data baru yang butuh otorisasi berbeda.
     */
    public function monitoring(Request $request): JsonResponse
    {
        $this->authorize('viewAny', VisitAssignment::class);

        $puskesmasId = $request->filled('puskesmas_id') ? $request->integer('puskesmas_id') : null;

        return ApiResponse::success($this->service->monitoringSummary($request->user(), $puskesmasId));
    }

    /**
     * Detail SATU kunjungan (revisi Bu Kadis -- halaman dashboard/kunjungan/[id].vue) -- eager
     * load SAMA PERSIS index() (termasuk latestReport.attendees utk PMO/kunjungan berombongan)
     * supaya shape VisitAssignmentResource konsisten antara list & detail, cuma beda granularitas
     * akses (satu baris vs paginated). Policy::view() sudah scoped per role (kader/nakes: milik
     * sendiri, admin_puskesmas/pj_prolanis: puskesmas sendiri, super_admin: semua).
     */
    public function show(VisitAssignment $visitAssignment): JsonResponse
    {
        $this->authorize('view', $visitAssignment);

        $visitAssignment->load([
            'patient', 'kader.user', 'tenagaKesehatan.user', 'assignedBy', 'puskesmasSnapshot', 'companions.kader.user',
            'latestReport.pjReviewedBy', 'latestReport.validatedBy', 'latestReport.attendees.kader.user',
        ]);

        return ApiResponse::success(new VisitAssignmentResource($visitAssignment));
    }

    /**
     * PJ Prolanis (atau admin_puskesmas/super_admin) menugaskan kader ke pasien.
     */
    public function store(CreateVisitAssignmentRequest $request): JsonResponse
    {
        $patient = PatientsCache::findOrFail($request->validated('patient_id'));
        $kader = Kader::findOrFail($request->validated('kader_id'));

        $this->authorize('create', [VisitAssignment::class, $patient, $kader]);

        $assignment = $this->service->assign(
            $patient,
            $kader,
            $request->user(),
            $request->validated('scheduled_date'),
            $request->validated('priority'),
        );

        // Revisi Bu Kadis: kader yang baru ditugaskan otomatis dapat rencana kunjungan
        // BERULANG mingguan (pendampingan minum obat) -- idempotent, lihat docblock service.
        $this->careAssignmentService->ensureKaderPlan($assignment);

        $assignment->load(['patient', 'kader.user', 'assignedBy', 'companions.kader.user']);

        return ApiResponse::success(new VisitAssignmentResource($assignment), 'Assignment berhasil dibuat', 201);
    }

    /**
     * Tugaskan kader yang SAMA ke banyak pasien sekaligus (docs/planning/02 §12/§16) -- kader
     * primer dicek sekali (Policy::createBulk), tiap pasien divalidasi lewat jalur yang sama
     * seperti assignment tunggal. PARTIAL SUCCESS: yang lolos tetap dibuat meski ada yang gagal.
     * companion_kader_ids opsional (kunjungan berombongan) -- divalidasi (aktif+sepuskesmas
     * dengan kader primer) sekali di Service, gagal satu companion = seluruh batch ditolak.
     */
    public function bulkStore(BulkCreateVisitAssignmentRequest $request): JsonResponse
    {
        $kader = Kader::findOrFail($request->validated('kader_id'));
        $companionKaders = Kader::whereIn('id', $request->validated('companion_kader_ids', []))->get()->all();

        $this->authorize('createBulk', [VisitAssignment::class, $kader]);

        $result = $this->service->assignBulk(
            $request->validated('patient_ids'),
            $kader,
            $companionKaders,
            $request->user(),
            $request->validated('scheduled_date'),
            $request->validated('priority'),
        );

        foreach ($result['created'] as $assignment) {
            $this->careAssignmentService->ensureKaderPlan($assignment);
            $assignment->load(['patient', 'kader.user', 'assignedBy', 'companions.kader.user']);
        }

        $code = $result['created'] !== [] ? 201 : 200;

        return ApiResponse::success([
            'created' => VisitAssignmentResource::collection($result['created']),
            'failed' => $result['failed'],
        ], sprintf('%d assignment berhasil dibuat, %d gagal.', count($result['created']), count($result['failed'])), $code);
    }
}
