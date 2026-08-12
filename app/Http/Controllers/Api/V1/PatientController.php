<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\ListPatientsRequest;
use App\Http\Requests\Patient\ProposePatientUpdateRequest;
use App\Http\Requests\Patient\SearchPatientByNikRequest;
use App\Http\Resources\LabResultResource;
use App\Http\Resources\PatientResource;
use App\Http\Resources\RiskClassificationResource;
use App\Http\Resources\VisitAssignmentResource;
use App\Jobs\SyncPatientFieldUpdateToSilakesJob;
use App\Models\LabResultCache;
use App\Models\PatientsCache;
use App\Services\Notification\NotifiableTarget;
use App\Services\Notification\NotificationPayload;
use App\Services\Notification\NotifyService;
use App\Services\Patient\PatientQueryService;
use App\Services\Silakes\SilakesApiClient;
use App\Support\ApiResponse;
use App\Support\DataScope;
use App\Support\NikHasher;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PatientController extends Controller
{
    public function __construct(private readonly PatientQueryService $patientQuery) {}

    public function index(ListPatientsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', PatientsCache::class);

        $paginator = $this->applyFilters($this->patientQuery->scopedQuery($request->user()), $request)
            ->with(['desa', 'kecamatan', 'puskesmas', 'latestRiskClassification'])
            ->orderBy('nama')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success([
            'items' => PatientResource::collection($paginator),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Filter yang SAMA dipakai index() (JSON, paginated) dan exportPdf() (semua baris yang
     * match, tidak paginated) -- diekstrak ke satu tempat supaya kedua jalur tidak pernah drift
     * beda hasil (revisi Bu Kadis, Fase 5).
     *
     * @param  Builder<PatientsCache>  $query
     * @return Builder<PatientsCache>
     */
    private function applyFilters(Builder $query, Request $request): Builder
    {
        return $query
            ->when(
                $request->filled('wilayah_status'),
                fn ($q) => $q->where('wilayah_status', $request->string('wilayah_status'))
            )
            ->when(
                $request->filled('risk_level'),
                fn ($q) => $q->whereHas(
                    'latestRiskClassification',
                    fn ($riskQuery) => $riskQuery->where('level', $request->string('risk_level'))
                )
            )
            ->when(
                $request->filled('kecamatan_id'),
                fn ($q) => $q->where('kecamatan_id', $request->integer('kecamatan_id'))
            )
            // Revisi Bu Kadis (Fase 5) -- HANYA full-access (super_admin) yang boleh persempit
            // lewat param ini; admin_puskesmas/pj_prolanis SUDAH terkunci ke puskesmas sendiri
            // lewat scopedQuery() di atas, input mereka diam-diam diabaikan (bukan error 403 --
            // pola sama seperti DashboardService::summaryFor()).
            ->when(
                $request->filled('puskesmas_id') && DataScope::isFullAccess($request->user()),
                fn ($q) => $q->where('puskesmas_id', $request->integer('puskesmas_id'))
            )
            ->when(
                $request->filled('search'),
                fn ($q) => $q->where(function ($sub) use ($request) {
                    $term = '%'.addcslashes($request->string('search')->trim()->toString(), '%_\\').'%';
                    $sub->where('nama', 'like', $term)->orWhere('no_reg', 'like', $term);
                })
            );
    }

    /**
     * Unduh daftar pasien (filter QUERY PARAM SAMA persis dengan index()) sebagai PDF berkop
     * Dinkes P2KB Kabupaten Sumenep -- revisi Bu Kadis, Fase 5. TIDAK paginated (semua baris
     * yang match filter aktif diekspor) -- operator diharapkan mempersempit filter dulu di
     * /dashboard/pasien sebelum klik unduh, sama seperti tombol ini muncul di halaman itu.
     */
    public function exportPdf(ListPatientsRequest $request): Response
    {
        $this->authorize('viewAny', PatientsCache::class);

        $patients = $this->applyFilters($this->patientQuery->scopedQuery($request->user()), $request)
            ->with(['desa', 'kecamatan', 'puskesmas', 'latestRiskClassification'])
            ->orderBy('nama')
            ->get();

        $pdf = Pdf::loadView('pdf.patients-export', [
            'patients' => $patients,
            'generatedAt' => now(),
            'generatedBy' => $request->user(),
            'totalCount' => $patients->count(),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('data-pasien-prolanis-'.now()->format('Ymd-His').'.pdf');
    }

    /**
     * Riwayat klasifikasi risiko LENGKAP (bukan cuma is_latest=true) -- dipakai frontend untuk
     * seksi "Dasar Klasifikasi" (baris terbaru) dan "Riwayat & Tren Kondisi" (semua baris,
     * revisi Bu Kadis Fase 5). Dibatasi 100 baris terbaru -- classify() menulis baris baru
     * SETIAP kali dipanggil (bukan cuma saat level berubah, lihat RiskClassificationService),
     * jadi riwayat bisa tumbuh cukup panjang untuk pasien lama; 100 baris lebih dari cukup
     * untuk kebutuhan tren visual, bukan arsip audit penuh.
     */
    public function riskHistory(PatientsCache $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $history = $patient->riskClassifications()
            ->orderByDesc('computed_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return ApiResponse::success(RiskClassificationResource::collection($history));
    }

    /**
     * Riwayat kunjungan (kader MAUPUN tenaga_kesehatan, revisi Bu Kadis Fase 2/5) untuk 1
     * pasien -- mengisi seksi "Riwayat Kunjungan" di detail pasien yang sebelumnya placeholder
     * statis. Gerbang akses sama dengan show()/riskHistory() (PatientsCachePolicy::view).
     */
    public function visitHistory(PatientsCache $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $visits = $patient->visitAssignments()
            ->with(['kader.user', 'tenagaKesehatan.user', 'latestReport', 'puskesmasSnapshot'])
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return ApiResponse::success(VisitAssignmentResource::collection($visits));
    }

    /**
     * Hasil pemeriksaan lab TERBARU (1 baris per parameter, revisi Bu Kadis) untuk seksi
     * "Hasil Pemeriksaan Terakhir" di detail pasien -- SEMUA parameter yang pernah diperiksa
     * (bukan cuma yang exceeded/dipakai klasifikasi seperti criteria_snapshot di riskHistory()),
     * lengkap dengan nilai_rujukan ASLI dari SiLAKES. Urutan "terbaru" SAMA PERSIS dengan
     * RiskClassificationService::classify() (tanggal_periksa lalu synced_at sebagai tiebreak)
     * supaya konsisten dengan hasil yang dipakai sistem untuk klasifikasi.
     */
    public function labResults(PatientsCache $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $latestPerParameter = LabResultCache::where('patient_id', $patient->external_patient_id)
            ->orderByDesc('tanggal_periksa')
            ->orderByDesc('synced_at')
            ->get()
            ->unique('parameter')
            ->sortBy('parameter')
            ->values();

        return ApiResponse::success(LabResultResource::collection($latestPerParameter));
    }

    public function show(PatientsCache $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $patient->load(['desa', 'kecamatan', 'puskesmas', 'latestRiskClassification']);

        return ApiResponse::success(new PatientResource($patient));
    }

    /**
     * Cari pasien via NIK persis (docs/planning §17 dashboard/pasien) -- PRODULI TIDAK PERNAH
     * menyimpan NIK asli (patients_cache cuma punya nik_hash HMAC dari SiLAKES), jadi ini
     * SATU-SATUNYA cara "cari by NIK" yang mungkin: hash input yang diketik user pakai kunci
     * yang SAMA dengan SiLAKES (produli.silakes.nik_hash_secret), lalu cocokkan hash-vs-hash.
     * TIDAK PERNAH bisa membalik nik_hash tersimpan jadi NIK asli untuk DITAMPILKAN -- itu
     * mustahil secara matematis (HMAC satu arah), bukan keterbatasan implementasi.
     *
     * POST (bukan GET) + wajib re-autentikasi password SENDIRI (bukan password pasien) --
     * pencarian identitas presisi ini lebih sensitif daripada browse list biasa, step-up auth
     * sebagai gerbang tambahan (docs/planning §17).
     */
    public function searchByNik(SearchPatientByNikRequest $request): JsonResponse
    {
        $this->authorize('viewAny', PatientsCache::class);

        if (! Hash::check($request->string('password'), $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => ['Password salah.'],
            ]);
        }

        $hash = NikHasher::hash($request->string('nik')->toString());

        $patient = $this->patientQuery->scopedQuery($request->user())
            ->with(['desa', 'kecamatan', 'puskesmas', 'latestRiskClassification'])
            ->where('nik_hash', $hash)
            ->first();

        if ($patient === null) {
            return ApiResponse::success(null, 'Pasien dengan NIK tersebut tidak ditemukan.');
        }

        return ApiResponse::success(new PatientResource($patient));
    }

    /**
     * Ajukan koreksi/pelengkapan data pasien dari STAF (admin_puskesmas/pj_prolanis lewat
     * dashboard/pasien) -- jalur PARALEL dari usulan kader lewat POST /visit-reports (itu
     * terikat satu laporan kunjungan spesifik). PatientsCachePolicy::update sudah scoped ke
     * puskesmas yang sama, jadi staf cuma bisa ajukan untuk pasien di wilayah kerjanya sendiri.
     * SELALU masuk sebagai pending_review di SiLAKES, TIDAK PERNAH auto-apply ke data PRODULI
     * sendiri (docs/planning/01 §9, sama seperti jalur kader).
     */
    public function proposeUpdate(ProposePatientUpdateRequest $request, PatientsCache $patient, NotifyService $notifyService): JsonResponse
    {
        $this->authorize('update', $patient);

        $fields = $request->proposedFields();

        if ($fields === []) {
            throw ValidationException::withMessages([
                'fields' => ['Isi minimal satu field yang ingin diajukan perubahannya.'],
            ]);
        }

        SyncPatientFieldUpdateToSilakesJob::dispatch($patient->id, $fields, $request->user()->name);

        // Puskesmas-scoped (revisi Bu Kadis) -- dikirim di titik INI (usulan diajukan), bukan
        // saat SiLAKES approve baliknya (SyncSilakesService, cron) yang tidak punya konteks
        // "siapa staf yang mengusulkan" sama sekali (delta sync terjadwal, bukan aksi user).
        if ($patient->puskesmas_id !== null) {
            $notifyService->notify(
                NotifiableTarget::puskesmas($patient->puskesmas_id),
                new NotificationPayload(
                    type: 'patient_updated',
                    title: 'Data Pasien Diperbarui',
                    body: "{$request->user()->name} mengajukan perubahan data pasien {$patient->nama}.",
                    data: [
                        'type' => 'patient_updated',
                        'patient_id' => $patient->id,
                        'patient_nama' => $patient->nama,
                        'updated_by' => $request->user()->name,
                    ],
                ),
                ['push', 'email'],
            );
        }

        return ApiResponse::success(null, 'Usulan perubahan data pasien berhasil diajukan ke SiLAKES untuk ditinjau.');
    }

    /**
     * Riwayat usulan perubahan data pasien yang PERNAH DIKIRIM dari PRODULI (dashboard staf
     * MAUPUN kunjungan kader) untuk 1 pasien, termasuk status pending_review/approved/rejected
     * -- dibaca LIVE dari SiLAKES (GET .../pembaruan-lapangan, SilakesApiClient), PRODULI tidak
     * menyimpan salinan lokal (SiLAKES tetap satu-satunya sumber kebenaran status approval).
     * Gerbang akses SAMA dengan proposeUpdate() -- kalau boleh mengajukan, boleh juga lihat
     * riwayatnya sendiri untuk pasien itu.
     */
    public function updateHistory(PatientsCache $patient, SilakesApiClient $client): JsonResponse
    {
        $this->authorize('update', $patient);

        $body = $client->getPembaruanLapanganHistory($patient->external_patient_id);

        return ApiResponse::success($body['data'] ?? []);
    }
}
