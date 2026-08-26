<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Visit\BulkCreateVisitAssignmentRequest;
use App\Http\Requests\Visit\CreateVisitAssignmentRequest;
use App\Http\Requests\Visit\MultiDateVisitAssignmentRequest;
use App\Http\Resources\VisitAssignmentResource;
use App\Models\Kader;
use App\Models\PatientsCache;
use App\Models\VisitAssignment;
use App\Services\Visit\CareAssignmentService;
use App\Services\Visit\VisitAssignmentService;
use App\Support\ApiResponse;
use App\Support\DataScope;
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
     *
     * Revisi (paginasi server-side dashboard/kunjungan) -- SEBELUMNYA frontend tarik SEMUA
     * baris (fetchAllPages, per_page=100 diulang sampai habis) lalu filter status/puskesmas/
     * search di JS, blok "Pagination" di UI cuma dekorasi (tidak fungsional). Filter tambahan di
     * sini supaya frontend bisa benar-benar paginate: search (nama pasien/kader/nakes), status
     * (termasuk 2 status TURUNAN yang tidak tersimpan sebagai kolom -- 'terlambat'/'diulang',
     * lihat dashboard/kunjungan/index.vue displayStatus()), puskesmas_id (super_admin only,
     * sama pola PatientController::applyFilters()).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', VisitAssignment::class);

        $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'string', 'in:pending,in_progress,completed,cancelled,terlambat,diulang'],
            'puskesmas_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->service->scopedQuery($request->user())
            ->with([
                // 'patient.desa'/'patient.kecamatan' (bukan cuma 'patient') -- dibutuhkan
                // VisitAssignmentResource untuk menandai pasien yang wilayahnya ambigu (desa_id
                // null) di fitur unduh peta offline (useMapTileDownload.ts, docs/planning/10 §5).
                'patient.desa', 'patient.kecamatan', 'kader.user', 'tenagaKesehatan.user', 'assignedBy', 'puskesmasSnapshot', 'companions.kader.user',
                'latestReport.pjReviewedBy', 'latestReport.validatedBy', 'latestReport.attendees.kader.user',
            ])
            ->when(
                $request->filled('puskesmas_id') && DataScope::isFullAccess($request->user()),
                fn ($query) => $query->where('puskesmas_id_snapshot', $request->integer('puskesmas_id'))
            )
            ->when(
                $request->filled('search'),
                function ($query) use ($request) {
                    $term = '%'.addcslashes($request->string('search')->trim()->toString(), '%_\\').'%';
                    $query->where(function ($sub) use ($term) {
                        $sub->whereHas('patient', fn ($p) => $p->where('nama', 'like', $term))
                            ->orWhereHas('kader.user', fn ($u) => $u->where('name', 'like', $term))
                            ->orWhereHas('tenagaKesehatan.user', fn ($u) => $u->where('name', 'like', $term));
                    });
                }
            )
            ->when(
                $request->filled('status'),
                function ($query) use ($request) {
                    $status = $request->string('status')->toString();

                    // 'terlambat'/'diulang' BUKAN kolom status tersimpan -- turunan dari
                    // status='pending' + scheduled_date lewat / latestReport ditolak (sama
                    // persis logic isOverdue()/isRepeat() di dashboard/kunjungan/index.vue,
                    // WAJIB tetap identik supaya jumlah hasil filter tidak pernah beda antara
                    // frontend lama & backend baru).
                    if ($status === 'terlambat') {
                        $query->where('status', 'pending')
                            ->whereDate('scheduled_date', '<', now()->toDateString())
                            ->whereDoesntHave('latestReport', fn ($r) => $r->where('validation_status', 'invalid'));
                    } elseif ($status === 'diulang') {
                        $query->where('status', 'pending')
                            ->whereHas('latestReport', fn ($r) => $r->where('validation_status', 'invalid'));
                    } else {
                        $query->where('status', $status);
                    }
                }
            )
            // Permintaan user -- KHUSUS super_admin/admin_puskesmas/pj_prolanis (role yang benar-
            // benar meninjau laporan masuk lewat /dashboard/kunjungan), kunjungan yang laporannya
            // BARU MASUK didahulukan (perlu ditinjau duluan), bukan diurutkan tenggat seperti
            // /app/tugas kader (yang sudah re-sort sendiri di frontend, jadi urutan default di
            // sini tidak berpengaruh ke kader). Subquery MAX(created_at) per assignment -- 1 baris
            // bisa punya >1 laporan (alur ulang setelah invalid, lihat latestReport() di atas).
            // MySQL taruh NULL (belum ada laporan) di posisi TERAKHIR untuk ORDER BY ... DESC
            // secara alami, jadi assignment yang belum ada laporan otomatis tidak mendahului yang
            // sudah ada laporan baru.
            ->when(
                $request->user()?->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis']),
                fn ($query) => $query->orderByRaw(
                    '(select max(vr.created_at) from visit_reports vr where vr.assignment_id = visit_assignments.id) desc'
                ),
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
     * Penugasan multi-tanggal (permintaan user) -- otorisasi SAMA PERSIS store() (patient+kader,
     * VisitAssignmentPolicy::create), bedanya cuma jumlah tanggal. Lihat docblock
     * VisitAssignmentService::assignMultipleDates() utk aturan lengkapnya (termasuk kenapa guard
     * "sudah punya assignment aktif" dilonggarkan di jalur ini).
     */
    public function storeMultiDate(MultiDateVisitAssignmentRequest $request): JsonResponse
    {
        $patient = PatientsCache::findOrFail($request->validated('patient_id'));
        $kader = Kader::findOrFail($request->validated('kader_id'));

        $this->authorize('create', [VisitAssignment::class, $patient, $kader]);

        $scheduledDates = $request->sortedScheduledDates();

        $assignments = $this->service->assignMultipleDates(
            $patient,
            $kader,
            $request->user(),
            $scheduledDates,
            $request->validated('priority'),
        );

        $this->careAssignmentService->ensureKaderPlanAdvancedTo(
            $patient->id,
            $kader->id,
            $request->user()->id,
            $assignments[0]->puskesmas_id_snapshot,
            end($scheduledDates),
        );

        foreach ($assignments as $assignment) {
            $assignment->load(['patient', 'kader.user', 'assignedBy']);
        }

        return ApiResponse::success(
            VisitAssignmentResource::collection($assignments),
            sprintf('%d penugasan berhasil dibuat.', count($assignments)),
            201,
        );
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

    /**
     * Batalkan penugasan (keputusan Kepala Dinas -- lihat VisitAssignmentPolicy::cancel()) --
     * admin_puskesmas/pj_prolanis sepuskesmas boleh langsung, TANPA approval super_admin. Alasan
     * pembatalan opsional (murni informatif, ikut dikirim ke notifikasi kader/nakes yang dibatalkan).
     */
    public function cancel(Request $request, VisitAssignment $visitAssignment): JsonResponse
    {
        $this->authorize('cancel', $visitAssignment);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $cancelled = $this->service->cancel($visitAssignment, $request->user(), $validated['reason'] ?? null);
        $cancelled->load(['patient', 'kader.user', 'tenagaKesehatan.user', 'assignedBy', 'puskesmasSnapshot', 'companions.kader.user']);

        return ApiResponse::success(new VisitAssignmentResource($cancelled), 'Penugasan berhasil dibatalkan');
    }
}
