<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SilakesApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\ListPatientsRequest;
use App\Http\Requests\Patient\ProposePatientUpdateRequest;
use App\Http\Requests\Patient\SearchPatientByNikRequest;
use App\Http\Resources\LabResultResource;
use App\Http\Resources\PatientResource;
use App\Http\Resources\RiskClassificationResource;
use App\Http\Resources\VisitAssignmentResource;
use App\Jobs\SyncPatientFieldUpdateToSilakesJob;
use App\Models\Kecamatan;
use App\Models\LabResultCache;
use App\Models\PatientsCache;
use App\Models\Puskesmas;
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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PatientController extends Controller
{
    /**
     * SAMA persis $riskLabels di resources/views/pdf/patients-export.blade.php -- satu sumber
     * dipakai controller (subjudul kop laporan) supaya label tidak pernah drift dari yang
     * ditampilkan di badge tabel.
     */
    private const RISK_LEVEL_LABELS = [
        'berat' => 'Berat',
        'sedang' => 'Sedang',
        'ringan' => 'Ringan',
        'tidak_berisiko' => 'Tidak Berisiko',
    ];

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
            // BUKAN cuma where('kecamatan_id', ...) langsung ke patients_cache.kecamatan_id --
            // kolom itu NULL untuk ~19,6% pasien yang puskesmas_id-nya justru SUDAH resolved
            // (lihat migration add_kecamatan_id_to_patients_cache_table, dan komentar
            // DashboardService::risikoPerKecamatan()). Filter lama ini diam-diam membuang
            // pasien seperti itu dari hasil (bug nyata: admin_puskesmas Pandian kehilangan 5
            // dari 6 pasien Risiko Berat gara-gara lockKecamatanToOwnPuskesmas() di frontend
            // mengirim kecamatan_id "cuma UX" yang ternyata BUKAN no-op). Precedence SAMA
            // PERSIS risikoPerKecamatan(): puskesmas_id->puskesmas.kecamatan_id PRIMARY,
            // patients_cache.kecamatan_id cuma fallback saat puskesmas_id null.
            ->when(
                $request->filled('kecamatan_id'),
                function ($q) use ($request) {
                    $kecamatanId = $request->integer('kecamatan_id');
                    $puskesmasIdsInKecamatan = Puskesmas::where('kecamatan_id', $kecamatanId)->pluck('id');

                    $q->where(function ($sub) use ($kecamatanId, $puskesmasIdsInKecamatan) {
                        $sub->whereIn('puskesmas_id', $puskesmasIdsInKecamatan)
                            ->orWhere(function ($fallback) use ($kecamatanId) {
                                $fallback->whereNull('puskesmas_id')->where('kecamatan_id', $kecamatanId);
                            });
                    });
                }
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
     * Dinas Kesehatan, Pengendalian Penduduk dan Keluarga Berencana Kabupaten Sumenep -- revisi
     * Bu Kadis, Fase 5. TIDAK paginated (semua baris
     * yang match filter aktif diekspor) -- operator diharapkan mempersempit filter dulu di
     * /dashboard/pasien sebelum klik unduh, sama seperti tombol ini muncul di halaman itu.
     *
     * Batas config('produli.reports.pdf_export_max_rows') DITEGAKKAN di sini (bukan cuma
     * diharapkan) -- sebelumnya endpoint ini tidak membatasi sama sekali, dompdf kehabisan
     * memory_limit dan proses PHP mati mendadak (500 kosong, tidak sempat tercatat log) kalau
     * operator lupa mempersempit filter dulu. Lihat docblock config/produli.php 'reports' utk
     * angka nyata hasil pengukuran.
     */
    public function exportPdf(ListPatientsRequest $request): Response
    {
        $this->authorize('viewAny', PatientsCache::class);

        $query = $this->applyFilters($this->patientQuery->scopedQuery($request->user()), $request);

        $maxRows = (int) config('produli.reports.pdf_export_max_rows');
        $matchedCount = (clone $query)->count();

        if ($matchedCount > $maxRows) {
            throw ValidationException::withMessages([
                'filter' => ["Data yang cocok filter ({$matchedCount} pasien) melebihi batas ekspor PDF ({$maxRows} pasien). Persempit filter dulu (mis. pilih puskesmas/kecamatan/tingkat risiko tertentu) sebelum mengunduh."],
            ]);
        }

        $patients = $query
            ->with(['desa', 'kecamatan', 'puskesmas', 'latestRiskClassification'])
            ->orderBy('nama')
            ->get();

        $pdf = Pdf::loadView('pdf.patients-export', [
            'patients' => $patients,
            'generatedAt' => now(),
            'generatedBy' => $request->user(),
            'totalCount' => $patients->count(),
            'filterSummary' => $this->resolveExportFilterSummary($request),
        ])->setPaper('a4', 'landscape');

        return $pdf->download($this->exportPdfFilename($request));
    }

    /**
     * Kalimat ringkasan filter wilayah/risiko AKTIF untuk subjudul kop laporan (revisi Bu
     * Kadis) -- "Puskesmas X" / "Kecamatan Y", disambung ", dengan klasifikasi tingkat risiko
     * Z" kalau risk_level ikut difilter. Gerbang wilayah SAMA persis exportPdfFilename()
     * (puskesmas_id cuma berlaku utk super_admin, sesuai applyFilters()) -- SENGAJA null kalau
     * filter itu tidak dipakai sama sekali (bukan "Seluruh Wilayah"), blade cuma menampilkan
     * baris ini kalau operator memang menyaring datanya.
     */
    private function resolveExportFilterSummary(Request $request): ?string
    {
        $wilayah = null;

        if ($request->filled('puskesmas_id') && DataScope::isFullAccess($request->user())) {
            $puskesmas = Puskesmas::find($request->integer('puskesmas_id'));
            // puskesmas.nama SUDAH termasuk prefix "Puskesmas " sendiri (lihat PuskesmasSeeder:
            // 'nama' => 'Puskesmas '.$namaPuskesmas) -- beda dari kecamatan.nama di bawah yang
            // TIDAK ada prefix "Kecamatan ", jangan disamakan polanya.
            $wilayah = $puskesmas !== null ? $puskesmas->nama : null;
        } elseif ($request->filled('kecamatan_id')) {
            $kecamatan = Kecamatan::find($request->integer('kecamatan_id'));
            $wilayah = $kecamatan !== null ? "Kecamatan {$kecamatan->nama}" : null;
        }

        $risiko = $request->filled('risk_level')
            ? (self::RISK_LEVEL_LABELS[$request->string('risk_level')->toString()] ?? null)
            : null;

        return match (true) {
            $wilayah !== null && $risiko !== null => "{$wilayah}, dengan klasifikasi tingkat risiko {$risiko}",
            $wilayah !== null => $wilayah,
            $risiko !== null => "Dengan klasifikasi tingkat risiko {$risiko}",
            default => null,
        };
    }

    /**
     * Nama file unduhan mengikuti filter wilayah/risiko yang AKTUAL dipakai (bukan cuma
     * timestamp generik) -- supaya operator yang unduh beberapa file berturut-turut (mis. per
     * puskesmas) tidak perlu buka satu-satu buat tahu isinya. Null-safe: kalau filter wilayah/
     * risiko tidak diisi (atau puskesmas_id-nya diabaikan karena bukan super_admin, sama seperti
     * applyFilters()), jatuh ke fallback "seluruh-wilayah"/"semua-risiko" -- tidak pernah
     * menghasilkan segmen kosong/tanda hubung dobel.
     */
    private function exportPdfFilename(Request $request): string
    {
        $wilayahSegment = 'seluruh-wilayah';

        if ($request->filled('puskesmas_id') && DataScope::isFullAccess($request->user())) {
            $puskesmas = Puskesmas::find($request->integer('puskesmas_id'));

            if ($puskesmas !== null) {
                // puskesmas.nama SUDAH termasuk kata "Puskesmas " sendiri (lihat PuskesmasSeeder),
                // jangan ditambah lagi -- beda dari kecamatan.nama di bawah yang polos tanpa
                // prefix "Kecamatan ".
                $wilayahSegment = Str::slug($puskesmas->nama);
            }
        } elseif ($request->filled('kecamatan_id')) {
            $kecamatan = Kecamatan::find($request->integer('kecamatan_id'));

            if ($kecamatan !== null) {
                $wilayahSegment = 'kecamatan-'.Str::slug($kecamatan->nama);
            }
        }

        $risikoSegment = $request->filled('risk_level')
            ? 'risiko-'.Str::slug($request->string('risk_level')->toString())
            : 'semua-risiko';

        return "data-pasien-prolanis-{$wilayahSegment}-{$risikoSegment}-".now()->format('Ymd-His').'.pdf';
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
     * Cari pasien via NIK persis (docs/planning §17 dashboard/pasien) -- tetap lewat jalur
     * hash-vs-hash (hash input yang diketik user pakai kunci yang SAMA dengan SiLAKES,
     * produli.silakes.nik_hash_secret, lalu cocokkan ke nik_hash tersimpan), BUKAN query
     * langsung ke kolom nik. patients_cache sejak revisi Kepala Dinas juga menyimpan NIK asli
     * (dipakai jalur PDF export, lihat App\Support\NikDisplay), tapi pencarian ini sengaja
     * tetap lewat hash supaya perilakunya tidak berubah dan tetap konsisten kalau nik_hash
     * dan nik pernah tidak sinkron.
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

        try {
            $body = $client->getPembaruanLapanganHistory($patient->external_patient_id);
        } catch (SilakesApiException $e) {
            // 404 dari SiLAKES = pasien ini belum/tidak dikenal di sana (mis. pasien simulasi
            // sintetis di lingkungan dev, atau pasien asli yang belum pernah punya pengajuan
            // sama sekali) -- itu bukan error, cuma berarti "belum ada riwayat". Selain 404
            // (5xx dkk) tetap dilempar ulang, jangan disembunyikan.
            if ($e->statusCode !== 404) {
                throw $e;
            }

            return ApiResponse::success([]);
        }

        return ApiResponse::success($body['data'] ?? []);
    }
}
