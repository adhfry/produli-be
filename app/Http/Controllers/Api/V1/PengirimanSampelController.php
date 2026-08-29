<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PengirimanSampel\AddPatientToPengirimanSampelRequest;
use App\Http\Requests\PengirimanSampel\ReorderPengirimanSampelRequest;
use App\Http\Resources\PatientCandidateResource;
use App\Http\Resources\PengirimanSampelPasienResource;
use App\Http\Resources\PengirimanSampelResource;
use App\Models\PengirimanSampel;
use App\Models\PengirimanSampelPasien;
use App\Services\PengirimanSampel\PengirimanSampelService;
use App\Support\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fase B modul "Kirim Data Prolanis ke Labkesda Sumenep" -- penyusun antrian (murni dalam
 * PRODULI, belum ada pengiriman sungguhan ke SiLAKES). Gating akses lewat `$this->authorize()`
 * ke PengirimanSampelPolicy, bukan route middleware `role:` -- PRODULI backend tidak punya
 * middleware role apa pun, semua gating lewat Policy class (lihat pola TenagaKesehatanController/
 * PengantarSampelController).
 */
class PengirimanSampelController extends Controller
{
    public function __construct(private readonly PengirimanSampelService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PengirimanSampel::class);

        $paginator = $this->service->scopedQuery($request->user())
            ->with(['puskesmas', 'dibuatOleh', 'pengantarSampel.user'])
            ->withCount('pasien')
            ->when(
                $request->user()->hasRole('super_admin') && $request->filled('puskesmas_id'),
                fn ($query) => $query->where('puskesmas_id', $request->integer('puskesmas_id'))
            )
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status'))
            )
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success([
            'items' => PengirimanSampelResource::collection($paginator),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, PengirimanSampel $pengirimanSampel): JsonResponse
    {
        $this->authorize('view', $pengirimanSampel);

        $pengirimanSampel->load(['puskesmas', 'dibuatOleh', 'pengantarSampel.user', 'pasien']);

        return ApiResponse::success(new PengirimanSampelResource($pengirimanSampel));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', PengirimanSampel::class);

        $puskesmasId = $request->user()->hasRole('super_admin') ? $request->integer('puskesmas_id') : null;
        $pengirimanSampel = $this->service->create($request->user(), $puskesmasId);
        $pengirimanSampel->load(['puskesmas', 'dibuatOleh']);

        return ApiResponse::success(new PengirimanSampelResource($pengirimanSampel), 'Antrian pengiriman sampel berhasil dibuat', 201);
    }

    public function addPatient(AddPatientToPengirimanSampelRequest $request, PengirimanSampel $pengirimanSampel): JsonResponse
    {
        $this->authorize('update', $pengirimanSampel);

        $pasien = $this->service->addPatient($pengirimanSampel, $request->validated());

        return ApiResponse::success(new PengirimanSampelPasienResource($pasien), 'Pasien berhasil ditambahkan ke antrian', 201);
    }

    public function removePatient(Request $request, PengirimanSampel $pengirimanSampel, PengirimanSampelPasien $pasien): JsonResponse
    {
        $this->authorize('update', $pengirimanSampel);

        $this->service->removePatient($pengirimanSampel, $pasien);

        return ApiResponse::success(null, 'Pasien berhasil dihapus dari antrian');
    }

    public function reorder(ReorderPengirimanSampelRequest $request, PengirimanSampel $pengirimanSampel): JsonResponse
    {
        $this->authorize('update', $pengirimanSampel);

        $updated = $this->service->reorder($pengirimanSampel, $request->validated()['pasien_ids']);
        $updated->load('pasien');

        return ApiResponse::success(new PengirimanSampelResource($updated), 'Urutan antrian berhasil diperbarui');
    }

    public function lock(Request $request, PengirimanSampel $pengirimanSampel): JsonResponse
    {
        $this->authorize('lock', $pengirimanSampel);

        $updated = $this->service->lock($pengirimanSampel, $request->user());

        return ApiResponse::success(new PengirimanSampelResource($updated), 'Daftar antrian berhasil dikunci');
    }

    public function unlock(Request $request, PengirimanSampel $pengirimanSampel): JsonResponse
    {
        $this->authorize('unlock', $pengirimanSampel);

        $updated = $this->service->unlock($pengirimanSampel);

        return ApiResponse::success(new PengirimanSampelResource($updated), 'Daftar antrian bisa diedit kembali');
    }

    public function cancel(Request $request, PengirimanSampel $pengirimanSampel): JsonResponse
    {
        $this->authorize('cancel', $pengirimanSampel);

        $updated = $this->service->cancel($pengirimanSampel);

        return ApiResponse::success(new PengirimanSampelResource($updated), 'Antrian berhasil dibatalkan');
    }

    /**
     * Fase C -- tugaskan pengantar sampel, hanya valid dari status 'terkunci'.
     */
    public function assignCourier(Request $request, PengirimanSampel $pengirimanSampel): JsonResponse
    {
        $this->authorize('assignCourier', $pengirimanSampel);

        $validated = $request->validate([
            'pengantar_sampel_id' => ['required', 'integer'],
        ]);

        $updated = $this->service->assignCourier($pengirimanSampel, $validated['pengantar_sampel_id'], $request->user());

        return ApiResponse::success(new PengirimanSampelResource($updated), 'Pengantar sampel berhasil ditugaskan');
    }

    /**
     * Kandidat pasien Prolanis utk dipilih ke antrian (checkbox+cari di halaman penyusun) --
     * lihat docblock PengirimanSampelService::patientCandidatesQuery().
     */
    public function patientCandidates(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PengirimanSampel::class);

        $paginator = $this->service->patientCandidatesQuery($request->user())
            ->when(
                $request->filled('search'),
                fn ($query) => $query->where('nama', 'like', '%'.$request->string('search').'%')
            )
            ->orderBy('nama')
            ->paginate($request->integer('per_page', 100));

        return ApiResponse::success([
            'items' => PatientCandidateResource::collection($paginator),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Fase C -- posisi TERKINI kurir yang sedang OTW, dipanggil peta live super_admin setelah
     * menerima sinyal realtime 'sampel.lokasi_berubah' (lihat PengirimanSampelLokasiService).
     * Payload sengaja tipis (lat/lng/accuracy/waktu saja) -- ini BUKAN endpoint umum, cuma
     * konsumen tunggalnya adalah peta live.
     */
    public function lokasi(Request $request, PengirimanSampel $pengirimanSampel): JsonResponse
    {
        $this->authorize('view', $pengirimanSampel);

        $lokasi = $pengirimanSampel->lokasi;

        return ApiResponse::success($lokasi ? [
            'latitude' => (float) $lokasi->latitude,
            'longitude' => (float) $lokasi->longitude,
            'accuracy' => $lokasi->accuracy !== null ? (float) $lokasi->accuracy : null,
            'recorded_at' => $lokasi->recorded_at->toIso8601String(),
        ] : null);
    }

    public function exportPdf(PengirimanSampel $pengirimanSampel): Response
    {
        $this->authorize('view', $pengirimanSampel);

        $pengirimanSampel->load(['puskesmas', 'pengantarSampel.user', 'pasien']);

        $pdf = Pdf::loadView('pdf.antrian-sampel', [
            'pengirimanSampel' => $pengirimanSampel,
            'generatedAt' => now(),
            'generatedBy' => request()->user(),
        ])->setPaper('a4', 'portrait');

        $filename = 'antrian-sampel-'.$pengirimanSampel->puskesmas->kode_internal.'-'.$pengirimanSampel->id.'.pdf';

        return $pdf->download($filename);
    }
}
