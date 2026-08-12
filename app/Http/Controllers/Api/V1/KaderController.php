<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kader\RegisterKaderRequest;
use App\Http\Requests\Kader\UpdateKaderProfileRequest;
use App\Http\Resources\KaderResource;
use App\Models\Kader;
use App\Models\VisitReport;
use App\Services\Kader\KaderService;
use App\Services\Silakes\SilakesApiClient;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

class KaderController extends Controller
{
    public function __construct(private readonly KaderService $service) {}

    /**
     * List kader milik puskesmas (docs/planning/02 §7) -- super_admin: semua (bisa filter
     * puskesmas_id), admin_puskesmas/pj_prolanis: puskesmas sendiri saja.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Kader::class);

        $paginator = $this->service->scopedQuery($request->user())
            ->with(['user', 'puskesmas', 'pj'])
            ->when(
                $request->user()->hasRole('super_admin') && $request->filled('puskesmas_id'),
                fn ($query) => $query->where('puskesmas_id', $request->integer('puskesmas_id'))
            )
            ->when(
                $request->filled('status_aktif'),
                fn ($query) => $query->where('status_aktif', $request->boolean('status_aktif'))
            )
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success([
            'items' => KaderResource::collection($paginator),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * PJ Prolanis (atau admin_puskesmas/super_admin) mendaftarkan kader baru.
     */
    public function store(RegisterKaderRequest $request): JsonResponse
    {
        $this->authorize('create', Kader::class);

        $kader = $this->service->register($request->user(), $request->validated());
        $kader->load(['user', 'puskesmas', 'pj']);

        return ApiResponse::success(new KaderResource($kader), 'Kader berhasil didaftarkan', 201);
    }

    /**
     * Dropdown pilihan PJ Prolanis untuk registrasi kader (docs/planning/02 §7) -- dipakai
     * admin_puskesmas/super_admin (pj_prolanis tidak perlu ini, pj_id-nya otomatis dirinya
     * sendiri). Gerbang SAMA seperti store() -- pertanyaan "siapa boleh coba daftarkan kader"
     * dan "siapa boleh lihat opsi PJ untuk itu" identik, jadi reuse Policy::create yang sama.
     */
    public function pjOptions(Request $request): JsonResponse
    {
        $this->authorize('create', Kader::class);

        $puskesmasId = $request->filled('puskesmas_id') ? $request->integer('puskesmas_id') : null;

        $options = $this->service->pjOptionsQuery($request->user(), $puskesmasId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($pj) => ['id' => $pj->id, 'name' => $pj->name]);

        return ApiResponse::success($options);
    }

    /**
     * Self-service: kader baca profilnya SENDIRI (puskesmas + PJ Prolanis ikut ter-load) --
     * dipakai /onboarding (docs/planning/02 §14) supaya langkah "Konfirmasi Penempatan"
     * menampilkan unit kerja & supervisor SUNGGUHAN, bukan placeholder statis.
     */
    public function showProfile(Request $request): JsonResponse
    {
        $kader = $request->user()->kader;

        if ($kader === null) {
            throw ValidationException::withMessages([
                'kader' => ['Akun Anda belum punya profil kader.'],
            ]);
        }

        $this->authorize('viewOwnProfile', $kader);

        return ApiResponse::success(new KaderResource($kader->load(['user', 'puskesmas', 'pj'])));
    }

    /**
     * Self-service: kader update profilnya SENDIRI (no_wa/alamat/gender/tgl_lahir) -- tidak
     * ada parameter ID di route, selalu beroperasi di profil kader milik user yang login
     * (docs/planning/02 §7: "kader hanya bisa edit profilnya sendiri, bukan punya orang lain").
     */
    public function updateProfile(UpdateKaderProfileRequest $request): JsonResponse
    {
        $kader = $request->user()->kader;

        if ($kader === null) {
            throw ValidationException::withMessages([
                'kader' => ['Akun Anda belum punya profil kader.'],
            ]);
        }

        $this->authorize('updateOwnProfile', $kader);

        $updated = $this->service->updateOwnProfile($kader, $request->validated());

        return ApiResponse::success(new KaderResource($updated), 'Profil berhasil diperbarui');
    }

    /**
     * Aktifkan/nonaktifkan kader -- admin_puskesmas/pj_prolanis sepuskesmas dengan kader ini,
     * atau super_admin (KaderPolicy::update, sama gerbang dengan aksi kelola kader lain).
     */
    public function setStatus(Request $request, Kader $kader): JsonResponse
    {
        $this->authorize('update', $kader);

        $validated = $request->validate([
            'status_aktif' => ['required', 'boolean'],
        ]);

        $updated = $this->service->setActive($kader, $validated['status_aktif']);
        $updated->load(['user', 'puskesmas', 'pj']);

        $message = $validated['status_aktif'] ? 'Kader berhasil diaktifkan' : 'Kader berhasil dinonaktifkan';

        return ApiResponse::success(new KaderResource($updated), $message);
    }

    /**
     * Self-service: riwayat pengajuan pembaruan data pasien (geo/kontak/identitas) yang
     * PERNAH DIAJUKAN kader ini sendiri saat kunjungan (docs/planning/01 §9) -- ruang lingkup
     * /app (kader), TERPISAH dari riwayat sisi staf (/dashboard/pasien/{id}, PatientController::
     * updateHistory) yang di-scope per pasien, bukan per kader.
     *
     * Dua lapis status per baris:
     * - push_status (lokal, visit_reports.sync_status): apakah usulan BERHASIL TERKIRIM ke
     *   SiLAKES sama sekali (pending = job belum diproses, synced = terkirim, failed = gagal
     *   setelah retry habis) -- lihat SyncFieldUpdateToSilakesJob.
     * - fields[].status (SiLAKES, patient_field_updates.status): kalau sudah terkirim, apakah
     *   DISETUJUI/DITOLAK staf Labkesda -- dibaca LIVE, PRODULI tidak menyimpan salinan lokal.
     *
     * Hanya laporan kunjungan yang BENAR-BENAR mengusulkan sesuatu yang disertakan (geo
     * dikonfirmasi ATAU field lain diisi) -- laporan biasa tanpa usulan apa pun (mayoritas)
     * sengaja tidak ikut muncul, itu bukan "pengajuan" sama sekali.
     */
    public function updateRequests(Request $request, SilakesApiClient $client): JsonResponse
    {
        $kader = $request->user()->kader;

        if ($kader === null) {
            throw ValidationException::withMessages([
                'kader' => ['Akun Anda belum punya profil kader.'],
            ]);
        }

        $paginator = VisitReport::query()
            ->whereHas('assignment', fn ($q) => $q->where('kader_id', $kader->id))
            ->where(fn ($q) => $q->whereNotNull('patient_field_updates')->orWhereNotNull('latitude'))
            ->with('assignment.patient')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        $reports = collect($paginator->items());

        // Kelompokkan per external_patient_id supaya SiLAKES cuma dipanggil SEKALI per pasien
        // unik di halaman ini (bukan sekali per laporan) -- kader bisa saja mengunjungi pasien
        // yang sama berkali-kali.
        $historyByPatient = $reports
            ->pluck('assignment.patient.external_patient_id')
            ->unique()
            ->filter()
            ->mapWithKeys(function (int $externalPatientId) use ($client) {
                try {
                    $body = $client->getPembaruanLapanganHistory($externalPatientId);

                    return [$externalPatientId => collect($body['data'] ?? [])->groupBy('kopipu_visit_id')];
                } catch (Throwable $e) {
                    report($e);

                    return [$externalPatientId => collect()];
                }
            });

        $data = $reports->map(function (VisitReport $report) use ($historyByPatient) {
            $patient = $report->assignment->patient;
            /** @var Collection $fieldsForThisVisit */
            $fieldsForThisVisit = $historyByPatient->get($patient->external_patient_id, collect())
                ->get($report->id, collect());

            return [
                'visit_report_id' => $report->id,
                'patient_id' => $patient->id,
                'patient_nama' => $patient->nama,
                'kunjungan_tanggal' => $report->created_at?->toIso8601String(),
                'push_status' => $report->sync_status,
                'push_error' => $report->sync_error,
                'fields' => $fieldsForThisVisit->map(fn ($row) => [
                    'kategori' => $row['kategori'],
                    'field_name' => $row['field_name'],
                    'old_value' => $row['old_value'],
                    'new_value' => $row['new_value'],
                    'status' => $row['status'],
                    'reviewed_at' => $row['reviewed_at'],
                    'catatan_reviewer' => $row['catatan_reviewer'],
                ])->values(),
            ];
        })->values();

        return ApiResponse::success([
            'items' => $data,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
