<?php

namespace Database\Seeders;

use App\Models\RiskThreshold;
use Illuminate\Database\Seeder;

/**
 * Nilai rujukan klinis resmi (docs/planning/02 §3, disediakan langsung oleh operator, bukan
 * hasil tebakan) -- 1 baris per parameter, SEMUA strict greater-than (bukan >=). Nama
 * `parameter` HARUS persis sama dengan kolom lab_results_cache.parameter dari SiLAKES asli
 * (dikonfirmasi dari data sync nyata: "Gula Darah Puasa" bukan singkatan "GDP"; "Cholesterol"
 * bukan "Cholesterol Total"; "LDL" bukan "Cholesterol LDL" -- lihat riwayat percakapan).
 *
 * Kolom `level` di sini SENGAJA cuma label metadata untuk criteria_snapshot (audit) --
 * RiskClassificationService::determineLevel() TIDAK membaca kolom ini untuk memutuskan level
 * akhir (itu logic lintas-parameter di kode, lihat BERAT_PARAMETERS/SEDANG_PARAMETERS). Semua
 * diberi label 'sedang' di sini karena operator cuma menyediakan SATU batas per parameter
 * (bukan dua tingkat sedang/berat terpisah seperti draf awal).
 *
 * Idempotent -- upsert lewat (parameter, level), sesuai unique constraint migration.
 */
class RiskThresholdSeeder extends Seeder
{
    /**
     * @var array<int, array{parameter: string, threshold_min: float}>
     */
    private const NILAI_RUJUKAN = [
        ['parameter' => 'Gula Darah Puasa', 'threshold_min' => 120],
        ['parameter' => 'Creatinine', 'threshold_min' => 1.7],
        ['parameter' => 'Cholesterol', 'threshold_min' => 200],
        ['parameter' => 'Trigliserida', 'threshold_min' => 140],
        ['parameter' => 'LDL', 'threshold_min' => 130],
        ['parameter' => 'Urea', 'threshold_min' => 46],
    ];

    public function run(): void
    {
        foreach (self::NILAI_RUJUKAN as $row) {
            RiskThreshold::updateOrCreate(
                ['parameter' => $row['parameter'], 'level' => 'sedang'],
                [
                    'operator' => '>',
                    'threshold_min' => $row['threshold_min'],
                    'threshold_max' => null,
                    'is_active' => true,
                ],
            );
        }

        $this->command?->info('Selesai: '.count(self::NILAI_RUJUKAN).' baris risk_thresholds ter-upsert.');
    }
}
