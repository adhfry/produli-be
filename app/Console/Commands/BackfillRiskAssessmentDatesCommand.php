<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill risk_classifications.assessment_date untuk baris LAMA yang dibuat SEBELUM kolom ini
 * ada (migration 2026_08_18_100000, commit "fix: tren kondisi pakai tanggal lab asli") --
 * RiskClassificationService::classify() SUDAH benar mengisi assessment_date dari tanggal_periksa
 * lab untuk baris BARU, tapi baris lama tetap NULL selamanya sampai dibackfill manual (temuan
 * user, audit database: 9.466 dari 10.406 baris / 4.464 pasien terdampak per 2026-08-27).
 *
 * Dampak baris NULL: "Riwayat & Tren Kondisi" (chart) & filter periode (PatientController::
 * index()) jatuh ke fallback COALESCE(assessment_date, computed_at) -- computed_at cuma kapan
 * job reclassify/sync KEBETULAN jalan (bisa berdekatan beberapa hari padahal data lab aslinya
 * berbulan-bulan lampau, mis. 2 baris klasifikasi computed_at 12 & 17 Agustus tapi SAMA-SAMA
 * dari data lab 8 Mei -- kejadian nyata pasien A. JAZILI id 9976 yang memicu audit ini), membuat
 * grafik tren & fitur "lihat kondisi bulan X" menyesatkan. RiskTransitionScorer (skoring kinerja
 * puskesmas) JUGA baca assessment_date sbg jendela waktu -- TAPI baris risk_transition_scores
 * yang SUDAH ada tidak ikut ter-refresh oleh backfill ini (idempotent by current_risk_
 * classification_id), butuh langkah terpisah kalau mau direcompute, SENGAJA tidak dilakukan
 * command ini (scope-nya murni assessment_date, lihat produli:backfill-risk-transition-scores
 * utk yang itu).
 *
 * Rekonstruksi: assessment_date = tanggal_periksa TERBARU dari lab_results_cache pasien ybs
 * yang SUDAH diketahui sistem (synced_at) pada saat computed_at baris klasifikasi itu -- replika
 * persis logika $latestResultPerParameter di classify(), bukan tebakan. Baris tanpa lab_results_
 * cache sama sekali pada saat itu (mis. klasifikasi 'tidak_berisiko' default tanpa data) tetap
 * NULL setelah backfill -- tidak ada tanggal nyata utk direkonstruksi, itu bukan kegagalan.
 *
 * Aman dijalankan berkali-kali -- HANYA menyentuh baris assessment_date IS NULL, tidak pernah
 * menimpa nilai yang sudah terisi (baik dari classify() asli maupun dari backfill sebelumnya).
 */
class BackfillRiskAssessmentDatesCommand extends Command
{
    protected $signature = 'produli:backfill-risk-assessment-dates {--dry-run}';

    protected $description = 'Backfill assessment_date baris risk_classifications lama dari tanggal_periksa lab_results_cache';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $totalNull = DB::table('risk_classifications')->whereNull('assessment_date')->count();
        $this->info("Baris assessment_date IS NULL saat ini: {$totalNull}.");

        if ($totalNull === 0) {
            $this->info('Tidak ada yang perlu di-backfill.');

            return self::SUCCESS;
        }

        $wouldFillCount = DB::table('risk_classifications as rc')
            ->join('patients_cache as pc', 'pc.id', '=', 'rc.patient_id')
            ->whereNull('rc.assessment_date')
            ->whereExists(function ($query) {
                $query->selectRaw(1)
                    ->from('lab_results_cache as lrc')
                    ->whereColumn('lrc.patient_id', 'pc.external_patient_id')
                    ->whereColumn('lrc.synced_at', '<=', 'rc.computed_at');
            })
            ->count();

        $this->info("Baris yang akan terisi (punya data lab sebelum computed_at-nya): {$wouldFillCount}.");
        $this->info('Sisanya ('.($totalNull - $wouldFillCount).') tetap NULL -- tidak ada lab_results_cache yang cocok utk direkonstruksi.');

        if ($dryRun) {
            $this->warn('--dry-run: tidak ada yang ditulis.');

            return self::SUCCESS;
        }

        if ($wouldFillCount === 0) {
            $this->info('Tidak ada baris yang bisa direkonstruksi (tidak ada lab_results_cache yang cocok) -- tidak ada yang perlu ditulis.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Lanjutkan mengisi {$wouldFillCount} baris risk_classifications.assessment_date sekarang?", false)) {
            $this->warn('Dibatalkan oleh operator.');

            return self::SUCCESS;
        }

        // Correlated subquery murni (BUKAN UPDATE...JOIN, itu sintaks MySQL-spesifik) supaya
        // portable ke SQLite juga (test suite jalan di SQLite in-memory, lihat phpunit.xml).
        $updated = DB::update('
            UPDATE risk_classifications
            SET assessment_date = (
                SELECT MAX(lrc.tanggal_periksa)
                FROM lab_results_cache lrc
                WHERE lrc.patient_id = (
                    SELECT pc.external_patient_id FROM patients_cache pc WHERE pc.id = risk_classifications.patient_id
                )
                AND lrc.synced_at <= risk_classifications.computed_at
            )
            WHERE assessment_date IS NULL
        ');

        $remainingNull = DB::table('risk_classifications')->whereNull('assessment_date')->count();
        $this->info("SELESAI -- {$updated} baris ter-update, {$remainingNull} baris tetap NULL (tidak ada data lab yang cocok).");

        return self::SUCCESS;
    }
}
