<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kader\RegisterKaderRequest;
use App\Http\Requests\Kader\UpdateKaderProfileRequest;
use App\Http\Resources\KaderResource;
use App\Models\Kader;
use App\Services\Kader\KaderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
}
