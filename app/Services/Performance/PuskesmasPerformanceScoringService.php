<?php

namespace App\Services\Performance;

use App\Models\Puskesmas;
use App\Models\RiskTransitionScore;
use Carbon\Carbon;

/**
 * Leaderboard "Top 5 Puskesmas Kinerja Terbaik" (menggantikan formula lama
 * DashboardService::puskesmasPerformance() yang cuma menghitung transisi level mentah tanpa
 * syarat kunjungan tervalidasi -- lihat riwayat percakapan). Dibaca dari agregat
 * risk_transition_scores yang SUDAH dihitung RiskTransitionScorer saat sync/reclassify terjadi
 * (bukan real-time recompute dari nol tiap dashboard dibuka).
 *
 * Formula (spesifikasi scoring kinerja puskesmas):
 * - improvement_rate (bobot 50%) = pasien eligible yang membaik (final_point > 0) / total
 *   pasien eligible, dalam persen.
 * - risk_reduction_score (bobot 30%) = rata-rata risk_delta transisi eligible, dinormalisasi
 *   dari rentang [-3, +3] ke skala [0, 100].
 * - stability_rate (bobot 20%) = dari transisi eligible yang BERANGKAT dari 'tidak_berisiko'
 *   (previous_risk_level), berapa persen yang BERTAHAN 'tidak_berisiko' (current_risk_level
 *   sama) -- retensi pasien yang sudah terkendali, bukan sekadar pasien baru membaik.
 * - final_score = improvement_rate*0.5 + risk_reduction_score*0.3 + stability_rate*0.2,
 *   dibatasi [0, 100].
 *
 * Puskesmas TANPA transisi eligible sama sekali di periode filter TIDAK muncul di hasil (bukan
 * skor 0) -- tidak ada dasar untuk dinilai, beda dari "dinilai dan hasilnya nol".
 *
 * Aturan v2 (blueprint docs/planning/16 §4e, 2026-11-17): eligible = transisi karena pemeriksaan
 * lab baru; periode difilter dengan TANGGAL PEMERIKSAAN (assessment_date), bukan calculated_at
 * (yang menumpuk di tanggal hitung ulang). Setiap baris membawa rincian poin: perbaikan,
 * perburukan, retensi, bonus kunjungan, dan total.
 *
 * @see RiskTransitionScorer cara 1 baris risk_transition_scores dihitung & syarat eligible.
 */
class PuskesmasPerformanceScoringService
{
    /**
     * @return array<int, array{
     *     rank: int, puskesmas_id: int, puskesmas_nama: string, final_score: float,
     *     improvement_rate: float, risk_reduction_score: float, stability_rate: float,
     *     eligible_patients: int, improved_patients: int, validated_visits: int,
     *     total_improvement_points: int
     * }>
     */
    public function topPerforming(?Carbon $dateFrom, ?Carbon $dateTo): array
    {
        $scores = $this->transisiPeriode($dateFrom, $dateTo)->whereNotNull('puskesmas_id')->get();

        if ($scores->isEmpty()) {
            return [];
        }

        $rows = [];

        foreach ($scores->groupBy('puskesmas_id') as $puskesmasId => $puskesmasScores) {
            $eligible = $puskesmasScores->where('eligible', true);
            $eligiblePatients = $eligible->pluck('patient_id')->unique()->count();

            if ($eligiblePatients === 0) {
                // Ada transisi tercatat (mis. semuanya tanpa kunjungan tervalidasi) tapi tidak
                // satupun eligible -- puskesmas ini tidak punya dasar dinilai periode ini.
                continue;
            }

            $improvedPatients = $eligible->filter(fn ($row) => $row->final_point > 0)
                ->pluck('patient_id')->unique()->count();

            $totalImprovementPoints = (int) $eligible->sum('final_point');
            $improvementRate = $this->clampPercent(($improvedPatients / $eligiblePatients) * 100);

            $avgDelta = (float) $eligible->avg('risk_delta');
            $riskReductionScore = $this->clampPercent((($avgDelta + 3) / 6) * 100);

            $terkendaliBaseline = $eligible->where('previous_risk_level', 'tidak_berisiko');
            $terkendaliRetained = $terkendaliBaseline->where('current_risk_level', 'tidak_berisiko');
            $stabilityRate = $terkendaliBaseline->count() > 0
                ? $this->clampPercent(($terkendaliRetained->count() / $terkendaliBaseline->count()) * 100)
                : 0.0;

            $finalScore = $this->clampPercent(
                $improvementRate * 0.5 + $riskReductionScore * 0.3 + $stabilityRate * 0.2
            );

            $validatedVisits = $eligible->pluck('related_validated_visit_id')->filter()->unique()->count();
            $retensi = $eligible->filter(fn ($row) => $row->previous_risk_level === 'tidak_berisiko' && $row->current_risk_level === 'tidak_berisiko');
            $lainnya = $eligible->diff($retensi);

            $rows[] = [
                'puskesmas_id' => (int) $puskesmasId,
                'final_score' => $finalScore,
                'improvement_rate' => $improvementRate,
                'risk_reduction_score' => $riskReductionScore,
                'stability_rate' => $stabilityRate,
                'eligible_patients' => $eligiblePatients,
                'improved_patients' => $improvedPatients,
                'validated_visits' => $validatedVisits,
                'total_improvement_points' => $totalImprovementPoints,
                // Rincian poin (Fase 5): total_poin = perbaikan + perburukan + retensi + bonus.
                'poin_perbaikan' => (int) $lainnya->where('base_point', '>', 0)->sum('base_point'),
                'poin_perburukan' => (int) $lainnya->where('base_point', '<', 0)->sum('base_point'),
                'poin_retensi' => (int) $retensi->sum('base_point'),
                'bonus_kunjungan' => (int) $eligible->sum('visit_bonus_point'),
                'total_poin' => $totalImprovementPoints,
                'worsened_patients' => $eligible->filter(fn ($row) => $row->final_point < 0)->pluck('patient_id')->unique()->count(),
            ];
        }

        if ($rows === []) {
            return [];
        }

        // Tie-breaker deterministik (spesifikasi): final_score -> total_improvement_points ->
        // improvement_rate -> eligible_patients, semua menurun (DESC).
        usort($rows, fn ($a, $b) => ($b['final_score'] <=> $a['final_score'])
            ?: ($b['total_improvement_points'] <=> $a['total_improvement_points'])
            ?: ($b['improvement_rate'] <=> $a['improvement_rate'])
            ?: ($b['eligible_patients'] <=> $a['eligible_patients']));

        $puskesmasNames = Puskesmas::query()
            ->whereIn('id', array_column($rows, 'puskesmas_id'))
            ->pluck('nama', 'id');

        return array_values(array_map(
            fn (array $row, int $index) => [
                'rank' => $index + 1,
                'puskesmas_nama' => $puskesmasNames->get($row['puskesmas_id'], '-'),
                ...$row,
            ],
            $rows,
            array_keys($rows),
        ));
    }

    /**
     * Transisi pasien yang MEMBAIK (atau memburuk) di satu puskesmas pada periode, untuk rincian
     * "pasien yang membaik" di halaman kinerja. Terbaru dulu.
     *
     * @return \Illuminate\Support\Collection<int, RiskTransitionScore>
     */
    public function transisiPuskesmas(int $puskesmasId, ?Carbon $dateFrom, ?Carbon $dateTo, string $arah = 'membaik')
    {
        return $this->transisiPeriode($dateFrom, $dateTo)
            ->where('puskesmas_id', $puskesmasId)
            ->where('eligible', true)
            ->where('final_point', $arah === 'memburuk' ? '<' : '>', 0)
            ->when($arah !== 'memburuk', fn ($q) => $q->whereColumn('previous_risk_level', '!=', 'current_risk_level'))
            ->with('patient:id,nama,no_reg,jenis_prolanis')
            ->orderByDesc('assessment_date')
            ->orderByDesc('id')
            ->limit(500)
            ->get();
    }

    /** Transisi yang tanggal PEMERIKSAAN-nya masuk periode (baris lama tanpa tanggal: calculated_at). */
    private function transisiPeriode(?Carbon $dateFrom, ?Carbon $dateTo)
    {
        // DATE(): sama di MySQL (kolom date) maupun SQLite test (tersimpan 'Y-m-d H:i:s').
        $tanggal = 'DATE(COALESCE(assessment_date, calculated_at))';

        return RiskTransitionScore::query()
            ->when($dateFrom, fn ($q) => $q->whereRaw("{$tanggal} >= ?", [$dateFrom->toDateString()]))
            ->when($dateTo, fn ($q) => $q->whereRaw("{$tanggal} <= ?", [$dateTo->toDateString()]));
    }

    private function clampPercent(float $value): float
    {
        return round(max(0.0, min(100.0, $value)), 2);
    }
}
