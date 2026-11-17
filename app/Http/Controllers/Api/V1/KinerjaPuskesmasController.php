<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Puskesmas;
use App\Models\RiskTransitionScore;
use App\Services\Performance\PuskesmasPerformanceScoringService;
use App\Support\ApiResponse;
use App\Support\RiskLevelScale;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kinerja puskesmas (Fase 5 blueprint docs/planning/16, 2026-11-17).
 *
 * - index: peringkat SE-KABUPATEN untuk semua role staf (sama dengan leaderboard dashboard), dengan
 *   rincian poin. Periode = tanggal pemeriksaan.
 * - pasien: daftar pasien yang membaik/memburuk di satu puskesmas. Berisi nama pasien, jadi
 *   admin_puskesmas/pj_prolanis hanya boleh melihat puskesmasnya sendiri; super_admin semua.
 * - ringkasan: kartu dashboard (membaik 1/7/30 hari, poin & peringkat bulan berjalan).
 */
class KinerjaPuskesmasController extends Controller
{
    private const ROLE_STAF = ['super_admin', 'admin_puskesmas', 'pj_prolanis'];

    public function __construct(private readonly PuskesmasPerformanceScoringService $scoring) {}

    public function index(Request $request): JsonResponse
    {
        $this->pastikanStaf($request);
        [$dari, $sampai] = $this->periode($request);

        return ApiResponse::success([
            'date_from' => $dari?->toDateString(),
            'date_to' => $sampai?->toDateString(),
            'aturan' => ['membaik_per_level' => 10, 'memburuk_per_level' => -10, 'retensi_terkendali' => 2, 'bonus_kunjungan' => 5],
            'peringkat' => $this->scoring->topPerforming($dari, $sampai),
        ]);
    }

    public function pasien(Request $request, Puskesmas $puskesmas): JsonResponse
    {
        $user = $this->pastikanStaf($request);
        if (! $user->hasRole('super_admin') && $user->puskesmas_id !== $puskesmas->id) {
            throw new AuthorizationException('Daftar pasien hanya bisa dilihat untuk puskesmas Anda sendiri.');
        }
        [$dari, $sampai] = $this->periode($request);
        $arah = $request->validate(['arah' => ['nullable', 'in:membaik,memburuk']])['arah'] ?? 'membaik';

        $baris = $this->scoring->transisiPuskesmas($puskesmas->id, $dari, $sampai, $arah)->map(fn (RiskTransitionScore $t) => [
            'patient_id' => $t->patient_id,
            'nama' => $t->patient?->nama,
            'no_reg' => $t->patient?->no_reg,
            'jenis_prolanis' => $t->patient?->jenis_prolanis,
            'dari' => $t->previous_risk_level,
            'ke' => $t->current_risk_level,
            'dari_label' => RiskLevelScale::scoringLabel($t->previous_risk_level),
            'ke_label' => RiskLevelScale::scoringLabel($t->current_risk_level),
            'tanggal_pemeriksaan' => $t->assessment_date?->toDateString(),
            'poin' => $t->base_point,
            'bonus_kunjungan' => $t->visit_bonus_point,
            'total' => $t->final_point,
        ])->values();

        return ApiResponse::success(['puskesmas' => ['id' => $puskesmas->id, 'nama' => $puskesmas->nama], 'arah' => $arah, 'items' => $baris]);
    }

    public function ringkasan(Request $request): JsonResponse
    {
        $user = $this->pastikanStaf($request);
        $puskesmasId = $user->hasRole('super_admin')
            ? ($request->integer('puskesmas_id') ?: null)
            : $user->puskesmas_id;

        $hitungMembaik = function (int $hari) use ($puskesmasId) {
            return RiskTransitionScore::query()
                ->where('eligible', true)
                ->where('final_point', '>', 0)
                ->whereColumn('previous_risk_level', '!=', 'current_risk_level')
                ->when($puskesmasId, fn ($q) => $q->where('puskesmas_id', $puskesmasId))
                ->whereRaw('DATE(COALESCE(assessment_date, calculated_at)) >= ?', [now()->subDays($hari)->toDateString()])
                ->distinct()
                ->count('patient_id');
        };

        $bulanIni = $this->scoring->topPerforming(now()->startOfMonth(), now());
        $baris = $puskesmasId ? collect($bulanIni)->firstWhere('puskesmas_id', $puskesmasId) : null;

        return ApiResponse::success([
            'puskesmas_id' => $puskesmasId,
            'membaik_1_hari' => $hitungMembaik(1),
            'membaik_7_hari' => $hitungMembaik(7),
            'membaik_30_hari' => $hitungMembaik(30),
            'bulan_ini' => [
                'peringkat' => $baris['rank'] ?? null,
                'dari' => count($bulanIni),
                'total_poin' => $baris['total_poin'] ?? null,
            ],
        ]);
    }

    private function pastikanStaf(Request $request)
    {
        $user = $request->user();
        if (! $user->hasAnyRole(self::ROLE_STAF)) {
            throw new AuthorizationException('Anda tidak memiliki akses ke kinerja puskesmas.');
        }

        return $user;
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private function periode(Request $request): array
    {
        $v = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        return [
            isset($v['date_from']) ? Carbon::parse($v['date_from']) : null,
            isset($v['date_to']) ? Carbon::parse($v['date_to']) : null,
        ];
    }
}
