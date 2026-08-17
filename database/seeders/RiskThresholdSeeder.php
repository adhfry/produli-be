<?php

namespace Database\Seeders;

use App\Models\RiskThreshold;
use Illuminate\Database\Seeder;

/**
 * Nilai rujukan klinis resmi (docs/planning/02 §3, disediakan langsung oleh operator, bukan
 * hasil tebakan) -- 1 baris per parameter, strict greater-than (>) secara default, KECUALI
 * Gula Darah Puasa (>=, lihat catatan di bawah) dan Creatinine (lihat DIRECT_CLASSIFIERS di
 * bawah). Nama `parameter` HARUS persis sama dengan kolom lab_results_cache.parameter dari
 * SiLAKES asli (dikonfirmasi dari data sync nyata: "Gula Darah Puasa" bukan singkatan "GDP";
 * "Cholesterol" bukan "Cholesterol Total"; "LDL" bukan "Cholesterol LDL" -- lihat riwayat
 * percakapan).
 *
 * Integrasi presisi SiLAKES (lihat App\Services\Risk\SilakesReferenceRangeService) SEMPAT
 * menjadikan 5 baris NILAI_RUJUKAN di bawah (semua kecuali Creatinine) sebagai FALLBACK,
 * bukan sumber utama -- tapi keputusan eksplisit user membatalkan itu:
 * SilakesReferenceRangeService::PARAMETER_MAP sekarang sengaja dikosongkan (lihat docblock
 * kelas itu), jadi baris di bawah ini KEMBALI jadi sumber utama untuk KESELURUHAN 6 parameter,
 * bukan cuma fallback. reference_ranges_cache & sinkronisasinya tetap ada (tidak dihapus),
 * tinggal dorman.
 *
 * Gula Darah Puasa: threshold_min 120->130, operator '>=' (bukan default '>') -- keputusan
 * eksplisit user menyamakan skema nilai rujukan GDP dengan standar resmi landing page
 * PRODULI ("Pemeriksaan & Nilai Rujukan"): "Normal jika <130, Tinggi jika >=130". Cutoff
 * tunggal 130 ini SENGAJA berbeda dari 3-tier diagnostik ADA 2026 (Normal <100, Prediabetes
 * 100-125, Diabetes Range >=126) yang dulu dipakai SiLAKES::reference_ranges untuk badge
 * indikasi per-parameter di laporan lab -- band SiLAKES untuk gula_darah_puasa SUDAH diubah
 * mengikuti cutoff 130 yang sama (lihat ReferenceRangeSeeder::gulaDarahPuasa() di repo
 * SiLAKES).
 *
 * Kolom `level` di NILAI_RUJUKAN (5 parameter, is_direct_classifier=false) SENGAJA cuma label
 * metadata untuk criteria_snapshot (audit) -- RiskClassificationService::determineLevel()
 * TIDAK membaca kolom ini untuk memutuskan level akhir (itu logic lintas-parameter di kode,
 * lihat BERAT_PARAMETERS/SEDANG_PARAMETERS). Semua diberi label 'sedang' di sini karena
 * operator cuma menyediakan SATU batas per parameter (bukan dua tingkat sedang/berat terpisah).
 *
 * DIRECT_CLASSIFIERS (revisi Bu Kadis) beda perlakuan: Creatinine BISA langsung menentukan
 * level pasien sendirian (1.7-1.9 mg/dL = Sedang, >=2.0 mg/dL = Berat) TANPA perlu 5 parameter
 * lain ikut melebihi rujukan -- kolom `level` di sini BENAR-BENAR dipakai RiskClassificationService
 * untuk menentukan level, bukan cuma metadata. Creatinine SENGAJA dikeluarkan dari
 * NILAI_RUJUKAN/BERAT_PARAMETERS supaya tidak dobel-hitung di kedua jalur.
 *
 * Idempotent -- upsert lewat (parameter, level), sesuai unique constraint migration.
 */
class RiskThresholdSeeder extends Seeder
{
    /**
     * @var array<int, array{parameter: string, threshold_min: float, operator?: string}>
     */
    private const NILAI_RUJUKAN = [
        ['parameter' => 'Gula Darah Puasa', 'threshold_min' => 130, 'operator' => '>='],
        ['parameter' => 'Cholesterol', 'threshold_min' => 200],
        ['parameter' => 'Trigliserida', 'threshold_min' => 140],
        ['parameter' => 'LDL', 'threshold_min' => 130],
        ['parameter' => 'Urea', 'threshold_min' => 46],
    ];

    /**
     * @var array<int, array{parameter: string, level: string, operator: string, threshold_min: float, threshold_max: ?float}>
     */
    private const DIRECT_CLASSIFIERS = [
        ['parameter' => 'Creatinine', 'level' => 'sedang', 'operator' => 'between', 'threshold_min' => 1.7, 'threshold_max' => 1.9],
        ['parameter' => 'Creatinine', 'level' => 'berat', 'operator' => '>=', 'threshold_min' => 2.0, 'threshold_max' => null],
    ];

    public function run(): void
    {
        foreach (self::NILAI_RUJUKAN as $row) {
            RiskThreshold::updateOrCreate(
                ['parameter' => $row['parameter'], 'level' => 'sedang'],
                [
                    'operator' => $row['operator'] ?? '>',
                    'is_direct_classifier' => false,
                    'threshold_min' => $row['threshold_min'],
                    'threshold_max' => null,
                    'is_active' => true,
                ],
            );
        }

        foreach (self::DIRECT_CLASSIFIERS as $row) {
            RiskThreshold::updateOrCreate(
                ['parameter' => $row['parameter'], 'level' => $row['level']],
                [
                    'operator' => $row['operator'],
                    'is_direct_classifier' => true,
                    'threshold_min' => $row['threshold_min'],
                    'threshold_max' => $row['threshold_max'],
                    'is_active' => true,
                ],
            );
        }

        $total = count(self::NILAI_RUJUKAN) + count(self::DIRECT_CLASSIFIERS);
        $this->command?->info("Selesai: {$total} baris risk_thresholds ter-upsert.");
    }
}
