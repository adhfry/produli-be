<?php

namespace App\Services\Risk;

use App\Models\ReferenceRangeCache;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Klasifikasi presisi umur+gender terhadap App\Models\ReferenceRangeCache (disinkron dari
 * SiLAKES via SyncSilakesService::syncReferenceRanges()) -- port PERSIS dari algoritma
 * App\Services\LabReferenceService::classify() di repo SiLAKES (ageMatches/valueMatches,
 * prioritas hari vs tahun untuk band neonatal). Dipakai RiskClassificationService sebagai
 * sumber "exceeded" presisi untuk 5 dari 6 parameter yang dipakainya (Gula Darah Puasa,
 * Cholesterol, Trigliserida, LDL, Urea) -- BUKAN Creatinine, lihat classify() docblock.
 *
 * INI SALINAN KE-3 dari algoritma classify() (setelah PHP SiLAKES & TypeScript SiLAKES) --
 * kalau SiLAKES mengubah STRUKTUR band (bukan cuma nilai cutoff, yang otomatis ter-refresh
 * lewat sync), logika di file ini juga harus di-port manual. Nilai cutoff sendiri TIDAK
 * pernah di-hardcode di sini -- selalu dibaca dari reference_ranges_cache hasil sync.
 */
class SilakesReferenceRangeService
{
    /**
     * Peta nama parameter RiskClassificationService (kolom lab_results_cache.parameter,
     * persis nama pemeriksaan SiLAKES) -> parameter_key SiLAKES. Creatinine SENGAJA tidak
     * dipetakan -- SiLAKES cuma punya 3 tier binary (Rendah/Normal/Tinggi) per parameter,
     * sementara RiskClassificationService butuh 2 tingkat keparahan terpisah (Sedang
     * 1.7-1.9, Berat >=2.0) lewat is_direct_classifier. Memaksakan mapping ini akan
     * menghilangkan pembedaan Sedang/Berat yang SiLAKES tidak punya presisi setara --
     * Creatinine TETAP 100% di jalur RiskThreshold lama (keputusan eksplisit, bukan lupa).
     *
     * @var array<string, string>
     */
    public const PARAMETER_MAP = [
        'Gula Darah Puasa' => 'gula_darah_puasa',
        'Cholesterol' => 'cholesterol_total',
        'Trigliserida' => 'trigliserida',
        'LDL' => 'ldl',
        'Urea' => 'urea',
    ];

    /** @var array<string, Collection<int, ReferenceRangeCache>>|null */
    private ?array $cache = null;

    public function isMapped(string $parameter): bool
    {
        return isset(self::PARAMETER_MAP[$parameter]);
    }

    public function resolveParameterKey(string $parameter): ?string
    {
        return self::PARAMETER_MAP[$parameter] ?? null;
    }

    /**
     * @return Collection<int, ReferenceRangeCache>
     */
    private function rangesFor(string $parameterKey): Collection
    {
        if ($this->cache === null) {
            $this->cache = ReferenceRangeCache::query()
                ->get()
                ->groupBy('parameter_key')
                ->all();
        }

        return $this->cache[$parameterKey] ?? collect();
    }

    /**
     * Klasifikasi satu nilai numerik terhadap band yang cocok (parameter_key x gender x
     * umur). null kalau tidak ada band yang cocok (data rujukan belum ditetapkan untuk
     * kombinasi umur/gender ini, ATAU cache belum pernah sync/kosong) -- caller (
     * RiskClassificationService) WAJIB fallback ke RiskThreshold lama untuk kasus ini,
     * BUKAN dianggap "exceeded=false" diam-diam.
     *
     * @return array{category: string, category_label: string, severity_rank: int, value_min: ?float, value_max: ?float, min_inclusive: bool, max_inclusive: bool}|null
     */
    public function classify(
        string $parameterKey,
        float $value,
        ?string $gender,
        ?CarbonInterface $birthDate,
        ?CarbonInterface $onDate = null,
    ): ?array {
        $onDate ??= Carbon::now();
        $ageYears = $birthDate ? (int) $birthDate->diffInYears($onDate) : null;
        $ageDays = $birthDate ? (int) $birthDate->diffInDays($onDate) : null;

        foreach ($this->rangesFor($parameterKey) as $range) {
            if ($range->gender !== null && $range->gender !== $gender) {
                continue;
            }

            if (! $this->ageMatches($range, $ageYears, $ageDays)) {
                continue;
            }

            if (! $this->valueMatches($range, $value)) {
                continue;
            }

            return [
                'category' => $range->category,
                'category_label' => $range->category_label,
                'severity_rank' => $range->severity_rank,
                'value_min' => $range->value_min,
                'value_max' => $range->value_max,
                'min_inclusive' => $range->min_inclusive,
                'max_inclusive' => $range->max_inclusive,
            ];
        }

        return null;
    }

    private function ageMatches(ReferenceRangeCache $range, ?int $ageYears, ?int $ageDays): bool
    {
        if ($range->age_min_days !== null || $range->age_max_days !== null) {
            if ($ageDays === null) {
                return false;
            }

            if ($range->age_min_days !== null && $ageDays < $range->age_min_days) {
                return false;
            }

            if ($range->age_max_days !== null && $ageDays > $range->age_max_days) {
                return false;
            }

            return true;
        }

        if ($range->age_min_years === null && $range->age_max_years === null) {
            return true;
        }

        if ($ageYears === null) {
            return false;
        }

        if ($range->age_min_years !== null && $ageYears < $range->age_min_years) {
            return false;
        }

        if ($range->age_max_years !== null && $ageYears > $range->age_max_years) {
            return false;
        }

        return true;
    }

    private function valueMatches(ReferenceRangeCache $range, float $value): bool
    {
        if ($range->value_min !== null) {
            $fails = $range->min_inclusive ? $value < $range->value_min : $value <= $range->value_min;
            if ($fails) {
                return false;
            }
        }

        if ($range->value_max !== null) {
            $fails = $range->max_inclusive ? $value > $range->value_max : $value >= $range->value_max;
            if ($fails) {
                return false;
            }
        }

        return true;
    }
}
