<?php

namespace App\Services\Risk;

use App\Models\LabResultCache;
use App\Models\PatientsCache;
use App\Models\RiskClassification;
use App\Models\RiskThreshold;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hitung level risiko dari lab_results_cache x risk_thresholds, simpan snapshot versioned.
 * Lihat docs/planning/02-arsitektur-backend-kopipu-smart.md §3.
 *
 * Kriteria (REVISI) — lintas-parameter, bukan lagi diambil dari level tertinggi per baris
 * threshold yang match:
 * - Berat: kelima-enam parameter di BERAT_PARAMETERS harus LENGKAP tersedia (hasil numerik
 *   untuk semuanya) DAN seluruhnya melebihi nilai rujukan sekaligus. Sengaja BUKAN "yang
 *   tersedia melebihi" secara longgar — kalau cuma Gula Darah Puasa yang pernah diperiksa
 *   (parameter lain belum ada hasil), itu TIDAK BOLEH otomatis jadi Berat (lihat definisi
 *   Ringan di bawah).
 * - Sedang: salah satu dari SEDANG_PARAMETERS (subset dari BERAT_PARAMETERS) melebihi rujukan,
 *   tapi tidak memenuhi kriteria Berat di atas.
 * - Ringan: HANYA Gula Darah Puasa yang melebihi rujukan di antara SEDANG_PARAMETERS (parameter
 *   lain di subset itu normal atau tidak diperiksa).
 *
 * Nama parameter di BERAT_PARAMETERS/SEDANG_PARAMETERS HARUS persis sama dengan kolom
 * lab_results_cache.parameter dari SiLAKES asli (dikonfirmasi dari data sync nyata) — bukan
 * singkatan medis umum. "GDP" (singkatan) TIDAK PERNAH muncul di data asli, yang ada adalah
 * "Gula Darah Puasa" -- salah satu bug yang pernah bikin patients_classified selalu 0.
 */
class RiskClassificationService
{
    private const BERAT_PARAMETERS = ['Gula Darah Puasa', 'Creatinine', 'Cholesterol', 'Trigliserida', 'LDL', 'Urea'];

    private const SEDANG_PARAMETERS = ['Gula Darah Puasa', 'Cholesterol', 'Trigliserida', 'LDL'];

    /**
     * Hitung ulang klasifikasi risiko pasien dari hasil lab terbaru per parameter.
     * Tidak melempar exception untuk nilai non-numerik — parameter itu di-skip + di-log
     * (docs/planning/01 §Catatan Implementasi Wajib), tidak menggagalkan proses sync.
     */
    public function classify(PatientsCache $patient): ?RiskClassification
    {
        // Urutkan berdasar tanggal pemeriksaan medis (bukan urutan/waktu sync) — delta sync
        // tidak menjamin urutan kronologis, hasil lama bisa tersinkron belakangan (docs/planning/02 §3).
        // synced_at sebagai tiebreak kalau tanggal_periksa sama persis (retest di hari yang sama).
        $latestResultPerParameter = LabResultCache::where('patient_id', $patient->external_patient_id)
            ->orderByDesc('tanggal_periksa')
            ->orderByDesc('synced_at')
            ->get()
            ->unique('parameter');

        $thresholds = RiskThreshold::where('is_active', true)->get()->groupBy('parameter');

        $criteria = [];
        $availableParameters = [];
        $exceededParameters = [];

        foreach ($latestResultPerParameter as $result) {
            if (! is_numeric($result->value)) {
                Log::warning('RiskClassificationService: nilai hasil lab non-numerik, parameter dilewati', [
                    'patient_id' => $patient->external_patient_id,
                    'parameter' => $result->parameter,
                    'value' => $result->value,
                ]);

                continue;
            }

            $availableParameters[] = $result->parameter;
            $value = (float) $result->value;
            $exceeded = false;

            foreach ($thresholds->get($result->parameter, collect()) as $threshold) {
                if (! $this->matchesThreshold($value, $threshold)) {
                    continue;
                }

                $exceeded = true;

                $criteria[] = [
                    'parameter' => $result->parameter,
                    'value' => $value,
                    'tanggal_periksa' => $result->tanggal_periksa?->toDateString(),
                    'operator' => $threshold->operator,
                    'threshold_min' => $threshold->threshold_min,
                    'threshold_max' => $threshold->threshold_max,
                    'level' => $threshold->level,
                ];
            }

            if ($exceeded) {
                $exceededParameters[] = $result->parameter;
            }
        }

        $matchedLevel = $this->determineLevel($availableParameters, $exceededParameters);

        if ($matchedLevel === null) {
            return null;
        }

        return DB::transaction(function () use ($patient, $matchedLevel, $criteria) {
            RiskClassification::where('patient_id', $patient->id)
                ->where('is_latest', true)
                ->update(['is_latest' => false]);

            return RiskClassification::create([
                'patient_id' => $patient->id,
                'level' => $matchedLevel,
                'criteria_snapshot' => $criteria,
                'computed_at' => now(),
                'is_latest' => true,
            ]);
        });
    }

    /**
     * @param  array<int, string>  $availableParameters  Parameter dengan hasil lab numerik (apapun nilainya).
     * @param  array<int, string>  $exceededParameters  Subset yang melebihi nilai rujukan.
     */
    private function determineLevel(array $availableParameters, array $exceededParameters): ?string
    {
        $beratLengkap = array_diff(self::BERAT_PARAMETERS, $availableParameters) === [];
        $beratSemuaMelebihi = array_diff(self::BERAT_PARAMETERS, $exceededParameters) === [];

        if ($beratLengkap && $beratSemuaMelebihi) {
            return 'berat';
        }

        $sedangExceeded = array_values(array_intersect(self::SEDANG_PARAMETERS, $exceededParameters));

        if ($sedangExceeded === []) {
            return null;
        }

        return $sedangExceeded === ['Gula Darah Puasa'] ? 'ringan' : 'sedang';
    }

    private function matchesThreshold(float $value, RiskThreshold $threshold): bool
    {
        return match ($threshold->operator) {
            '>' => $value > $threshold->threshold_min,
            '>=' => $value >= $threshold->threshold_min,
            '<' => $value < $threshold->threshold_min,
            '<=' => $value <= $threshold->threshold_min,
            'between' => $value >= $threshold->threshold_min && $value <= $threshold->threshold_max,
            default => false,
        };
    }
}
