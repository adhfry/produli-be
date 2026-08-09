<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\RegisterStaffRequest;
use App\Http\Resources\StaffResource;
use App\Services\Staff\StaffService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function __construct(private readonly StaffService $service) {}

    /**
     * List staf (admin_puskesmas + pj_prolanis), ter-scope sama seperti store() (docs/planning/02
     * §7/§11) -- super_admin lihat semua, admin_puskesmas/pj_prolanis cuma puskesmasnya sendiri
     * (StaffService::scopedQuery generik lewat puskesmas_id, tidak bercabang per role). Bukan
     * Policy resource (User bukan model domain KOPIPU sendiri), sama seperti store().
     */
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis'])) {
            throw new AuthorizationException('Hanya super_admin, admin_puskesmas, atau pj_prolanis yang bisa melihat daftar staf.');
        }

        $paginator = $this->service->scopedQuery($request->user())
            ->with('puskesmas')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success([
            'items' => StaffResource::collection($paginator),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * super_admin mendaftarkan admin_puskesmas/pj_prolanis baru; admin_puskesmas cuma boleh
     * mendaftarkan pj_prolanis untuk puskesmasnya sendiri (docs/planning/02 §11 -- koreksi dari
     * draf resmi yang tadinya keliru membatasi endpoint ini cuma super_admin). Pembatasan
     * spesifik "admin_puskesmas cuma boleh pj_prolanis, dipaksa puskesmas sendiri" ditegakkan di
     * StaffService, bukan di sini -- gerbang di controller cuma "role apa saja yang boleh
     * mencoba akses endpoint ini sama sekali". Bukan Policy resource (User bukan model domain
     * KOPIPU sendiri).
     */
    public function store(RegisterStaffRequest $request): JsonResponse
    {
        if (! $request->user()->hasAnyRole(['super_admin', 'admin_puskesmas'])) {
            throw new AuthorizationException('Hanya super_admin atau admin_puskesmas yang bisa mendaftarkan staf.');
        }

        $user = $this->service->register($request->user(), $request->validated());
        $user->load('puskesmas');

        return ApiResponse::success(new StaffResource($user), 'Staf berhasil didaftarkan', 201);
    }
}
