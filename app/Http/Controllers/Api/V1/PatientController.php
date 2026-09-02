<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\PatientsHasilExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\ListPatientsRequest;
use App\Http\Requests\Patient\OverridePuskesmasRequest;
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
use App\Services\Patient\HasilPemeriksaanExportService;
use App\Services\Patient\PatientQueryService;
use App\Services\Risk\RiskClassificationHistoryService;
use App\Services\Silakes\SilakesApiClient;
use App\Support\ApiResponse;
use App\Support\DataScope;
use App\Support\NikHasher;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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

    public function __construct(
        private readonly PatientQueryService $patientQuery,
        private readonly RiskClassificationHistoryService $riskHistory,
        private readonly HasilPemeriksaanExportService $hasilExport,
    ) {}

    public function index(ListPatientsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', PatientsCache::class);

        $paginator = $this->applySort($this->applyFilters($this->patientQuery->scopedQuery($request->user()), $request), $request)
            ->with(['desa', 'kecamatan', 'puskesmas', 'latestRiskClassification'])
            ->paginate($request->integer('per_page', 20));

        // Fitur periode bulanan (permintaan user) -- lampirkan status risiko pasien PERSIS
        // seperti kondisinya di akhir bulan yang diminta, di SAMPING (bukan menggantikan)
        // latestRiskClassification (status terkini) di atas. Query TERPISAH khusus utk ID
        // halaman yang sedang tampil (bukan semua pasien scope) -- murah & tidak mengubah
        // filter/sort utama sama sekali.
        if ($request->filled('period')) {
            $asOf = Carbon::parse($request->string('period').'-01')->endOfMonth();
            $patientIds = $paginator->getCollection()->pluck('id');
            $periodClassifications = $this->riskHistory
                ->effectiveQuery(PatientsCache::query()->whereIn('id', $patientIds), $asOf)
                ->select('risk_classifications.*')
                ->get()
                ->keyBy('patient_id');

            $paginator->getCollection()->each(function (PatientsCache $patient) use ($periodClassifications) {
                $patient->setRelation('periodRiskClassification', $periodClassifications->get($patient->id));
            });
        }

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
            // "Deteksi Dini Aktif" (revisi Bu Kadis) -- pasien Sedang yang berpotensi memburuk ke
            // Berat (RiskClassificationService::evaluateEarlyDetection()), dashboard/pasien perlu
            // filter cepat khusus kasus ini tanpa staf harus scroll manual cari ikon peringatan.
            ->when(
                $request->boolean('early_detection_only'),
                fn ($q) => $q->whereHas(
                    'latestRiskClassification',
                    fn ($riskQuery) => $riskQuery->where('early_detection_flag', true)
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
            // pola sama seperti DashboardService::summaryFor()). Tetap AMAN secara data (scope
            // tidak pernah lebar dari puskesmas sendiri), tapi silent-ignore ini dicatat ke log
            // (temuan audit, docs/planning/15) supaya percobaan filter di luar wewenang tetap
            // punya jejak, bukan hilang tanpa bekas saat diaudit nanti.
            ->when(
                $request->filled('puskesmas_id') && ! DataScope::isFullAccess($request->user()),
                function ($q) use ($request) {
                    Log::info('PatientController: puskesmas_id filter diabaikan, user bukan full-access', [
                        'user_id' => $request->user()->id,
                        'requested_puskesmas_id' => $request->integer('puskesmas_id'),
                    ]);

                    return $q;
                }
            )
            ->when(
                $request->filled('puskesmas_id') && DataScope::isFullAccess($request->user()),
                fn ($q) => $q->where('puskesmas_id', $request->integer('puskesmas_id'))
            )
            ->when(
                $request->filled('search'),
                fn ($q) => $q->where(function ($sub) use ($request) {
                    $term = '%'.addcslashes($request->string('search')->trim()->toString(), '%_\\').'%';
                    $sub->where('nama', 'like', $term)
                        ->orWhere('no_reg', 'like', $term)
                        ->orWhere('no_bpjs', 'like', $term);
                })
            );
    }

    /**
     * Sort tabel dashboard/pasien (revisi Bu Kadis) -- header nama/tingkat risiko bisa diklik utk
     * asc/desc. 'risk_level' BUKAN kolom langsung di patients_cache (ada di
     * latestRiskClassification), jadi butuh leftJoin + CASE expression -- urutan prioritas SAMA
     * PERSIS dashboard/pasien card ringkasan: berat(0) > sedang(1) > ringan(2) >
     * tidak_berisiko(3) > belum dihitung(4). early_detection_flag jadi tie-breaker KEDUA (bukan
     * filter, cuma pengurutan) supaya pasien Sedang yang berpotensi memburuk ke Berat naik ke
     * atas dalam grup 'sedang'-nya sendiri, tanpa mengubah urutan level lain.
     */
    private function applySort(Builder $query, Request $request): Builder
    {
        $sortBy = $request->string('sort_by')->toString() ?: 'nama';
        $direction = strtolower($request->string('sort_direction')->toString()) === 'desc' ? 'desc' : 'asc';

        if ($sortBy === 'risk_level') {
            return $query
                ->leftJoin('risk_classifications as sort_rc', function ($join) {
                    $join->on('sort_rc.patient_id', '=', 'patients_cache.id')
                        ->where('sort_rc.is_latest', true);
                })
                ->select('patients_cache.*')
                ->orderByRaw(
                    "CASE sort_rc.level
                        WHEN 'berat' THEN 0
                        WHEN 'sedang' THEN 1
                        WHEN 'ringan' THEN 2
                        WHEN 'tidak_berisiko' THEN 3
                        ELSE 4
                    END {$direction}"
                )
                ->orderByRaw('CASE WHEN sort_rc.early_detection_flag = 1 THEN 0 ELSE 1 END')
                ->orderBy('patients_cache.nama');
        }

        return $query->orderBy('patients_cache.nama', $direction);
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
        return "data-pasien-prolanis-{$this->wilayahFilenameSegment($request)}-{$this->risikoFilenameSegment($request)}-".now()->format('Ymd-His').'.pdf';
    }

    /**
     * Segmen "seluruh-wilayah" / "kecamatan-x" / slug nama puskesmas -- diekstrak dari
     * exportPdfFilename() supaya exportHasilPdf()/exportHasilExcel() di bawah pakai aturan
     * PERSIS SAMA tanpa duplikasi logic yang bisa drift. Gerbang wilayah SAMA persis
     * applyFilters()/resolveExportFilterSummary() (puskesmas_id cuma berlaku utk super_admin).
     */
    private function wilayahFilenameSegment(Request $request): string
    {
        if ($request->filled('puskesmas_id') && DataScope::isFullAccess($request->user())) {
            $puskesmas = Puskesmas::find($request->integer('puskesmas_id'));

            if ($puskesmas !== null) {
                // puskesmas.nama SUDAH termasuk kata "Puskesmas " sendiri (lihat PuskesmasSeeder),
                // jangan ditambah lagi -- beda dari kecamatan.nama di bawah yang polos tanpa
                // prefix "Kecamatan ".
                return Str::slug($puskesmas->nama);
            }
        } elseif ($request->filled('kecamatan_id')) {
            $kecamatan = Kecamatan::find($request->integer('kecamatan_id'));

            if ($kecamatan !== null) {
                return 'kecamatan-'.Str::slug($kecamatan->nama);
            }
        }

        return 'seluruh-wilayah';
    }

    /** Segmen "risiko-x" / "semua-risiko" -- lihat wilayahFilenameSegment() di atas. */
    private function risikoFilenameSegment(Request $request): string
    {
        return $request->filled('risk_level')
            ? 'risiko-'.Str::slug($request->string('risk_level')->toString())
            : 'semua-risiko';
    }

    /**
     * Nama file unduhan "Download Hasil" (PDF maupun Excel) -- aturan segmen SAMA PERSIS
     * exportPdfFilename() (lihat wilayahFilenameSegment()/risikoFilenameSegment() di atas),
     * cuma prefix & ekstensi yang beda.
     */
    private function exportHasilFilename(Request $request, string $extension): string
    {
        return "hasil-pemeriksaan-prolanis-{$this->wilayahFilenameSegment($request)}-{$this->risikoFilenameSegment($request)}-".now()->format('Ymd-His').".{$extension}";
    }

    /**
     * Unduh "Download Hasil" versi PDF (dashboard/pasien, permintaan user 2026-09-01) -- tabel
     * pasien terfilter dipivot jadi kolom TETAP & URUT per parameter pemeriksaan (GDP,
     * Cholesterol, Trigliserida, Urea, Creatinine, HDL, LDL, HbA1c, Microalbumin -- lihat
     * HasilPemeriksaanExportService::PARAMETER_COLUMNS), lihat service itu utk logika bersama
     * dgn exportHasilExcel() di bawah. Filter query param SAMA persis index()/exportPdf().
     *
     * Batas baris dihitung dari jumlah kolom TETAP di atas (lihat docblock config
     * produli.reports.hasil_pdf_export_max_cells) -- dompdf boros memori NON-LINEAR terhadap
     * jumlah SEL tabel, bukan cuma baris. Kalau operator butuh data lebih besar dari batas ini,
     * arahkan ke exportHasilExcel() (WithChunkReading, jauh lebih murah memori untuk data besar).
     */
    public function exportHasilPdf(ListPatientsRequest $request): Response
    {
        $this->authorize('viewAny', PatientsCache::class);

        $query = $this->applyFilters($this->patientQuery->scopedQuery($request->user()), $request);
        $parameters = $this->hasilExport->columnLabels();

        $maxCells = (int) config('produli.reports.hasil_pdf_export_max_cells');
        $columnCount = count($parameters) + 3; // Nama + FKTP + Desa/Kecamatan (kolom "No" diabaikan, tidak menambah beban render berarti)
        $maxRows = max(1, intdiv($maxCells, $columnCount));

        $matchedCount = (clone $query)->count();
        if ($matchedCount > $maxRows) {
            throw ValidationException::withMessages([
                'filter' => ["Data yang cocok filter ({$matchedCount} pasien, {$columnCount} kolom) melebihi batas ekspor PDF untuk jumlah kolom ini (maksimal {$maxRows} pasien). Persempit filter dulu, atau gunakan tombol Unduh Excel untuk data yang lebih besar."],
            ]);
        }

        // dompdf boros memori non-linear terhadap jumlah sel tabel (lihat docblock config
        // produli.reports) -- batas adaptif di atas sudah menahan kasus wajar, ini cuma
        // tambahan headroom, BUKAN pengganti batas itu.
        ini_set('memory_limit', config('produli.reports.hasil_pdf_export_memory_limit', '768M'));

        $patients = $query->with(['desa', 'kecamatan', 'puskesmas', 'labResults'])->orderBy('nama')->get();

        // Dikonversi ke array polos sebelum masuk blade (bukan lewat Eloquent model langsung) --
        // baris tabel di sini tetap punya lebih banyak kolom daripada patients-export.blade.php
        // biasa (9 parameter tetap + identitas), mengecilkan apa yang perlu ditahan dompdf di
        // memori saat merender selain data pasien mentahnya sendiri.
        $rows = $patients->map(function (PatientsCache $patient) {
            return [
                'nama' => $patient->nama,
                'nik' => $this->hasilExport->nikSubline($patient),
                'fktp' => $this->hasilExport->fktpLabel($patient),
                'wilayah' => $this->hasilExport->kelurahanKecamatan($patient),
                'values' => $this->hasilExport->rowCellData($patient->labResults),
            ];
        });
        $totalCount = $rows->count();
        unset($patients);

        $pdf = Pdf::loadView('pdf.patients-hasil-export', [
            'rows' => $rows,
            'parameters' => $parameters,
            'generatedAt' => now(),
            'generatedBy' => $request->user(),
            'totalCount' => $totalCount,
            'filterSummary' => $this->resolveExportFilterSummary($request),
        ])->setPaper('a4', 'landscape');

        return $pdf->download($this->exportHasilFilename($request, 'pdf'));
    }

    /**
     * Unduh "Download Hasil" versi Excel (dashboard/pasien, permintaan user 2026-09-01) --
     * jalur yang DIREKOMENDASIKAN untuk data besar (lihat docblock exportHasilPdf() di atas):
     * App\Exports\PatientsHasilExport pakai WithChunkReading, baris ditulis bertahap ke file,
     * tidak pernah menahan SEMUA pasien+hasil lab di memori sekaligus seperti dompdf.
     */
    public function exportHasilExcel(ListPatientsRequest $request): BinaryFileResponse
    {
        $this->authorize('viewAny', PatientsCache::class);

        $query = $this->applyFilters($this->patientQuery->scopedQuery($request->user()), $request);
        $parameters = $this->hasilExport->columnLabels();

        $maxRows = (int) config('produli.reports.hasil_excel_export_max_rows');
        $matchedCount = (clone $query)->count();

        if ($matchedCount > $maxRows) {
            throw ValidationException::withMessages([
                'filter' => ["Data yang cocok filter ({$matchedCount} pasien) melebihi batas ekspor Excel ({$maxRows} pasien). Persempit filter dulu (mis. pilih puskesmas/kecamatan/tingkat risiko tertentu) sebelum mengunduh."],
            ]);
        }

        return Excel::download(
            new PatientsHasilExport($query->orderBy('nama'), $parameters, $this->hasilExport),
            $this->exportHasilFilename($request, 'xlsx')
        );
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
            ->orderByRaw('COALESCE(assessment_date, computed_at) DESC')
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
            ->with(['kader.user', 'tenagaKesehatan.user', 'companions.kader.user', 'latestReport', 'puskesmasSnapshot'])
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

    /**
     * SELURUH riwayat hasil lab (bukan cuma terbaru per parameter seperti labResults() di atas)
     * -- fitur "Bandingkan Periode" (permintaan user, dashboard/pasien/{id}) numpang di sini,
     * frontend yang menghitung "nilai per parameter AS OF bulan X" dari histori lengkap ini
     * (pola sama dengan riskHistory() di atas yang sudah lebih dulu mengirim histori lengkap,
     * bukan cuma status terkini) -- TIDAK butuh endpoint komputasi baru di backend, cukup kirim
     * datanya, perbandingan 2 periode murni pengolahan di klien tanpa panggilan API tambahan
     * tiap kali user ganti periode yang dibandingkan.
     */
    public function labResultsHistory(PatientsCache $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $history = LabResultCache::where('patient_id', $patient->external_patient_id)
            ->orderByDesc('tanggal_periksa')
            ->orderByDesc('synced_at')
            ->limit(500)
            ->get();

        return ApiResponse::success(LabResultResource::collection($history));
    }

    public function show(PatientsCache $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $patient->load(['desa', 'kecamatan', 'puskesmas', 'latestRiskClassification', 'activeCareAssignments.kader.user', 'activeCareAssignments.tenagaKesehatan.user']);

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
     * Klaim manual pasien ke puskesmas TERTENTU, menimpa hasil resolusi otomatis WilayahResolver
     * (revisi Bu Kadis -- kasus "kunjungan khusus di luar wilayah desa/kecamatan pasien", mis.
     * Puskesmas Gapura punya pasien yang desa geografisnya resolve ke Kota Sumenep padahal
     * pengelola sesungguhnya Gapura). Otorisasi BUKAN PatientsCachePolicy::update biasa (itu
     * scoped ke puskesmas pasien SAAT INI -- puskesmas yang justru mau di-KOREKSI tidak akan
     * pernah lolos scope itu untuk klaim pasien MASUK ke puskesmasnya) -- di sini scoped ke
     * puskesmas TUJUAN (payload): super_admin bebas pilih puskesmas mana pun, admin_puskesmas/
     * pj_prolanis HANYA boleh klaim ke puskesmas mereka SENDIRI.
     *
     * Sekali di-set 'manual', SyncSilakesService/produli:reresolve-wilayah/produli:reconcile-
     * wilayah tidak akan pernah menimpanya lagi (lihat guard di ketiga tempat itu) -- kalau perlu
     * dikembalikan ke resolusi otomatis, jalankan produli:reresolve-wilayah setelah mengosongkan
     * puskesmas_resolution_method pasien ini secara manual (belum ada endpoint "batalkan override"
     * terpisah, sengaja -- kasus ini jarang terjadi & butuh keputusan sadar, bukan tombol sekali klik).
     */
    public function overridePuskesmas(OverridePuskesmasRequest $request, PatientsCache $patient): JsonResponse
    {
        $user = $request->user();
        $targetPuskesmasId = $request->integer('puskesmas_id');

        $isAllowed = DataScope::isFullAccess($user)
            || ($user->hasAnyRole(['admin_puskesmas', 'pj_prolanis']) && $user->puskesmas_id === $targetPuskesmasId);

        if (! $isAllowed) {
            throw new AuthorizationException('Anda hanya bisa mengklaim pasien ke puskesmas Anda sendiri.');
        }

        $patient->update([
            'puskesmas_id' => $targetPuskesmasId,
            'puskesmas_resolution_method' => 'manual',
        ]);

        Log::info('PatientController::overridePuskesmas -- puskesmas pasien diklaim manual', [
            'patient_id' => $patient->id,
            'puskesmas_id' => $targetPuskesmasId,
            'reason' => $request->string('reason')->toString() ?: null,
            'by_user_id' => $user->id,
        ]);

        return ApiResponse::success(new PatientResource($patient->fresh()), 'Puskesmas pasien berhasil diklaim manual.');
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
