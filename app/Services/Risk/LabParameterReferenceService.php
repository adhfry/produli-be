<?php

namespace App\Services\Risk;

use App\Models\RiskThreshold;

/**
 * Posisi 1 nilai lab terhadap ambang risiko PRODULI (permintaan user, fitur "Tren Hasil
 * Pemeriksaan" -- grafik % terhadap rujukan, BUKAN nilai mentah lintas-satuan yang tidak
 * sebanding). SENGAJA pakai risk_thresholds (ambang tunggal per parameter yang SUDAH aktif),
 * BUKAN SilakesReferenceRangeService/reference_ranges_cache (gender/umur-spesifik) -- itu
 * SENGAJA dinonaktifkan lewat keputusan eksplisit sebelumnya (lihat docblock kelas itu),
 * dikonfirmasi ulang ke user sebelum fitur ini dibuat: tetap pakai ambang tunggal.
 *
 * Read-only, TIDAK memanggil/mengubah RiskClassificationService -- semantik operator
 * direplikasi kecil di sini (matchesThreshold() private di sana), bukan reuse langsung, supaya
 * alur klasifikasi inti yang sudah teruji sama sekali tidak tersentuh oleh fitur tampilan ini.
 *
 * Parameter yang TIDAK punya risk_thresholds aktif sama sekali (mis. HDL, Microalbumin,
 * HbA1c -- datanya ADA di lab_results_cache tapi ambangnya sengaja belum dikonfigurasi)
 * SELALU mengembalikan semua field null -- BUKAN mengarang ambang, caller (LabResultResource)
 * wajib memperlakukan null sebagai "tidak relevan untuk grafik relatif", bukan "0%".
 */
class LabParameterReferenceService
{
    /** @var array<string, \Illuminate\Support\Collection<int, RiskThreshold>>|null */
    private ?array $cache = null;

    /**
     * @return array{reference_boundary: float|null, percent_of_reference: float|null, zone: string|null}
     */
    public function evaluate(string $parameter, float $value): array
    {
        $thresholds = $this->thresholdsFor($parameter);

        if ($thresholds->isEmpty()) {
            return ['reference_boundary' => null, 'percent_of_reference' => null, 'zone' => null];
        }

        // Batas "mulai ada perhatian" = threshold_min TERKECIL di antara semua tier (mis.
        // Creatinine: tier sedang 1.70 lebih kecil dari tier berat 2.00 -- 1.70 yang jadi 100%).
        $referenceBoundary = (float) $thresholds->min('threshold_min');

        $matchedLevel = null;
        foreach ($thresholds as $threshold) {
            if ($this->matches($value, $threshold) && $this->severity($threshold->level) > $this->severity($matchedLevel)) {
                $matchedLevel = $threshold->level;
            }
        }

        return [
            'reference_boundary' => $referenceBoundary,
            'percent_of_reference' => round($value / $referenceBoundary * 100, 1),
            'zone' => match ($matchedLevel) {
                null => 'normal',
                'berat' => 'tinggi',
                default => 'waspada', // 'ringan'/'sedang'
            },
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, RiskThreshold>
     */
    private function thresholdsFor(string $parameter): \Illuminate\Support\Collection
    {
        if ($this->cache === null) {
            $this->cache = RiskThreshold::where('is_active', true)
                ->get()
                ->groupBy('parameter')
                ->all();
        }

        return $this->cache[$parameter] ?? collect();
    }

    private function matches(float $value, RiskThreshold $threshold): bool
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

    private function severity(?string $level): int
    {
        return match ($level) {
            'berat' => 3,
            'sedang' => 2,
            'ringan' => 1,
            default => 0,
        };
    }
}
