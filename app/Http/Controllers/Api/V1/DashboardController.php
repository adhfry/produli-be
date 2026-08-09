<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DashboardSummaryResource;
use App\Services\Dashboard\DashboardService;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $service) {}

    /**
     * Ringkasan dashboard Dinkes/Puskesmas/PJ (docs/planning/02 §7) -- jumlah pasien per level
     * risiko & jumlah kunjungan per status, di-scope otomatis lewat DashboardService sesuai
     * role user (super_admin: semua data, admin_puskesmas/pj_prolanis: puskesmas sendiri,
     * kader: assignment miliknya sendiri).
     *
     * Filter opsional (query string, docs/planning §7 lanjutan -- kebutuhan enterprise/audit):
     * - puskesmas_id: HANYA berlaku utk super_admin (DashboardService diam-diam abaikan utk role
     *   lain -- mereka sudah terkunci ke puskesmasnya sendiri, bukan celah otorisasi).
     * - date_from/date_to: format Y-m-d, opsional independen (boleh isi salah satu). date_to
     *   WAJIB >= date_from kalau keduanya diisi.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        // Bukan Policy resource (dashboard bukan model) -- gerbangnya cuma "role staf yang
        // sah", scoping data yang sesungguhnya terjadi di DashboardService per role.
        if (! $user->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis', 'kader'])) {
            throw new AuthorizationException('Anda tidak memiliki akses untuk melihat dashboard ini');
        }

        $validated = $request->validate([
            'puskesmas_id' => ['nullable', 'integer', 'exists:puskesmas,id'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        $summary = $this->service->summaryFor(
            user: $user,
            puskesmasId: $validated['puskesmas_id'] ?? null,
            dateFrom: isset($validated['date_from']) ? Carbon::parse($validated['date_from']) : null,
            dateTo: isset($validated['date_to']) ? Carbon::parse($validated['date_to']) : null,
        );

        return ApiResponse::success(new DashboardSummaryResource($summary));
    }
}
