<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\ListPatientsRequest;
use App\Http\Requests\Patient\ProposePatientUpdateRequest;
use App\Http\Requests\Patient\SearchPatientByNikRequest;
use App\Http\Resources\PatientResource;
use App\Jobs\SyncPatientFieldUpdateToSilakesJob;
use App\Models\PatientsCache;
use App\Services\Patient\PatientQueryService;
use App\Services\Silakes\SilakesApiClient;
use App\Support\ApiResponse;
use App\Support\NikHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PatientController extends Controller
{
    public function __construct(private readonly PatientQueryService $patientQuery) {}

    public function index(ListPatientsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', PatientsCache::class);

        $paginator = $this->patientQuery->scopedQuery($request->user())
            ->with(['desa', 'kecamatan', 'puskesmas', 'latestRiskClassification'])
            ->when(
                $request->filled('wilayah_status'),
                fn ($query) => $query->where('wilayah_status', $request->string('wilayah_status'))
            )
            ->when(
                $request->filled('risk_level'),
                fn ($query) => $query->whereHas(
                    'latestRiskClassification',
                    fn ($riskQuery) => $riskQuery->where('level', $request->string('risk_level'))
                )
            )
            ->when(
                $request->filled('kecamatan_id'),
                fn ($query) => $query->where('kecamatan_id', $request->integer('kecamatan_id'))
            )
            ->when(
                $request->filled('search'),
                fn ($query) => $query->where(function ($sub) use ($request) {
                    $term = '%'.addcslashes($request->string('search')->trim()->toString(), '%_\\').'%';
                    $sub->where('nama', 'like', $term)->orWhere('no_reg', 'like', $term);
                })
            )
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

    public function show(PatientsCache $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $patient->load(['desa', 'kecamatan', 'puskesmas', 'latestRiskClassification']);

        return ApiResponse::success(new PatientResource($patient));
    }

    /**
     * Cari pasien via NIK persis (docs/planning §17 dashboard/pasien) -- KOPIPU TIDAK PERNAH
     * menyimpan NIK asli (patients_cache cuma punya nik_hash HMAC dari SiLAKES), jadi ini
     * SATU-SATUNYA cara "cari by NIK" yang mungkin: hash input yang diketik user pakai kunci
     * yang SAMA dengan SiLAKES (kopipu.silakes.nik_hash_secret), lalu cocokkan hash-vs-hash.
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
     * SELALU masuk sebagai pending_review di SiLAKES, TIDAK PERNAH auto-apply ke data KOPIPU
     * sendiri (docs/planning/01 §9, sama seperti jalur kader).
     */
    public function proposeUpdate(ProposePatientUpdateRequest $request, PatientsCache $patient): JsonResponse
    {
        $this->authorize('update', $patient);

        $fields = $request->proposedFields();

        if ($fields === []) {
            throw ValidationException::withMessages([
                'fields' => ['Isi minimal satu field yang ingin diajukan perubahannya.'],
            ]);
        }

        SyncPatientFieldUpdateToSilakesJob::dispatch($patient->id, $fields, $request->user()->name);

        return ApiResponse::success(null, 'Usulan perubahan data pasien berhasil diajukan ke SiLAKES untuk ditinjau.');
    }

    /**
     * Riwayat usulan perubahan data pasien yang PERNAH DIKIRIM dari KOPIPU (dashboard staf
     * MAUPUN kunjungan kader) untuk 1 pasien, termasuk status pending_review/approved/rejected
     * -- dibaca LIVE dari SiLAKES (GET .../pembaruan-lapangan, SilakesApiClient), KOPIPU tidak
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
