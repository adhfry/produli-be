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
 * prioritas hari vs tahun untuk band neonatal).
 *
 * NONAKTIF sejak keputusan eksplisit user (bukan dihapus -- lihat PARAMETER_MAP): standar
 * resmi PRODULI (landing page "Pemeriksaan & Nilai Rujukan") memakai SATU ambang tunggal per
 * parameter, tanpa tingkatan umur maupun gender -- selaras dengan sumber SiLAKES sendiri, yang
 * untuk kelima parameter ini (Gula Darah Puasa, Cholesterol, Trigliserida, LDL, Urea) TIDAK
 * PERNAH membedakan gender (kolom `gender` selalu null di ReferenceRangeSeeder repo SiLAKES),
 * dan populasi PRODULI 100% Prolanis dewasa/lansia sehingga tier anak-anak (2-17 tahun) pada
 * reference_ranges_cache tidak relevan -- lebih jauh, mencocokkan tanpa mempertimbangkan umur
 * TAPI tetap membaca band multi-tier itu akan ambigu (band anak & dewasa nilainya tumpang
 * tindih untuk parameter yang sama, lihat riwayat percakapan). PARAMETER_MAP sengaja dikosongkan
 * (bukan kelas ini dihapus) supaya reference_ranges_cache & sinkronisasinya tetap ada kalau
 * suatu saat dibutuhkan lagi, tapi RiskClassificationService::resolvePrecisionBand() otomatis
 * selalu fallback ke RiskThreshold (ambang tunggal) untuk semua parameter, bukan cuma sebagian.
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
     * persis nama pemeriksaan SiLAKES) -> parameter_key SiLAKES. SENGAJA KOSONG (lihat docblock
     * kelas) -- isMapped() jadi selalu false untuk parameter apa pun, sehingga
     * RiskClassificationService::resolvePrecisionBand() selalu fallback ke RiskThreshold
     * (ambang tunggal, tanpa umur/gender) untuk KESELURUHAN 6 parameter, termasuk Creatinine
     * yang memang sudah dari awal tidak pernah dipetakan ke sini.
     *
     * @var array<string, string>
     */
    public const PARAMETER_MAP = [];

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
