<?php

namespace App\Services\Patient;

use App\Models\LabResultCache;
use App\Models\PatientsCache;
use Illuminate\Support\Collection;

/**
 * Logika bersama tabel "Download Hasil" (dashboard/pasien, permintaan user 2026-09-01) --
 * pasien terfilter dipivot jadi 1 baris per pasien dengan kolom TETAP per parameter
 * pemeriksaan. Dipakai KEDUA jalur ekspor (PatientController::exportHasilPdf() dan
 * App\Exports\PatientsHasilExport untuk Excel) supaya nilai yang ditampilkan tidak pernah
 * drift antar format.
 */
class HasilPemeriksaanExportService
{
    /**
     * Kolom TETAP & URUT kiri-ke-kanan (permintaan user 2026-09-02) -- BUKAN lagi digali
     * dinamis dari data yang match filter. Key = nama PERSIS kolom lab_results_cache.parameter
     * dari SiLAKES asli (SUMBER SAMA dengan RiskClassificationService::BERAT_PARAMETERS/
     * SEDANG_PARAMETERS, dikonfirmasi ulang dari data sync nyata produksi 2026-09-02) -- value
     * = label kolom yang ditampilkan (mis. raw "Gula Darah Puasa" ditampilkan sbg singkatan
     * "GDP", permintaan user). Pasien yang tidak punya hasil parameter tsb (mis. pasien
     * Hipertensi tanpa GDP/HbA1c) TETAP dapat kolomnya, cuma isinya "-" (lihat cellValue()) --
     * bukan kolom yang hilang/collapse.
     */
    private const PARAMETER_COLUMNS = [
        'Gula Darah Puasa' => 'GDP',
        'Cholesterol' => 'Cholesterol',
        'Trigliserida' => 'Trigliserida',
        'Urea' => 'Urea',
        'Creatinine' => 'Creatinine',
        'HDL' => 'HDL',
        'LDL' => 'LDL',
        'HbA1c' => 'HbA1c',
        'Microalbumin' => 'Microalbumin',
    ];

    /**
     * Label kolom kiri-ke-kanan -- dipakai sbg header tabel PDF/Excel. TETAP & URUT, tidak
     * bergantung pasien/filter mana pun yang sedang diekspor.
     *
     * @return array<int, string>
     */
    public function columnLabels(): array
    {
        return array_values(self::PARAMETER_COLUMNS);
    }

    /**
     * Isi 1 baris pasien untuk SEMUA kolom parameter TETAP di atas, keyBy LABEL kolom (bukan
     * nama parameter mentah) dalam urutan PERSIS SAMA columnLabels() -- siap dipakai langsung
     * sbg 1 baris tabel PDF/Excel tanpa lookup tambahan di sisi caller.
     *
     * @param  Collection<int, LabResultCache>  $labResults
     * @return array<string, string>
     */
    public function rowValues(Collection $labResults): array
    {
        $latest = $this->latestPerParameter($labResults);

        $values = [];
        foreach (self::PARAMETER_COLUMNS as $rawParameter => $label) {
            $values[$label] = $this->cellValue($latest->get($rawParameter));
        }

        return $values;
    }

    /**
     * Hasil TERBARU per parameter dari koleksi lab_results_cache MILIK SATU PASIEN --
     * di-keyBy('parameter') supaya lookup per kolom di baris tabel O(1). Urutan tie-break SAMA
     * PERSIS query DB di PatientController::labResults() (ORDER BY tanggal_periksa DESC,
     * synced_at DESC), ditulis ulang sebagai in-memory sort di sini karena datanya sudah
     * di-eager-load lewat relasi 'labResults' (hasMany), bukan query baru per pasien --
     * menghindari N+1 untuk ratusan/ribuan pasien sekaligus di ekspor ini.
     *
     * @param  Collection<int, LabResultCache>  $labResults
     * @return Collection<string, LabResultCache>
     */
    private function latestPerParameter(Collection $labResults): Collection
    {
        return $labResults
            ->sort(function (LabResultCache $a, LabResultCache $b) {
                $tanggalCompare = ($b->tanggal_periksa?->timestamp ?? 0) <=> ($a->tanggal_periksa?->timestamp ?? 0);

                return $tanggalCompare !== 0
                    ? $tanggalCompare
                    : ($b->synced_at?->timestamp ?? 0) <=> ($a->synced_at?->timestamp ?? 0);
            })
            ->unique('parameter')
            ->keyBy('parameter');
    }

    /**
     * Teks 1 sel kolom parameter -- "126 mg/dL" kalau ada, "-" kalau pasien ini belum pernah
     * diperiksa parameter tsb sama sekali (mis. pasien Hipertensi murni tanpa GDP/HbA1c,
     * permintaan user -- kolom tetap muncul, isinya "-").
     */
    public function cellValue(?LabResultCache $result): string
    {
        if ($result === null) {
            return '-';
        }

        return trim($result->value.' '.($result->satuan ?? ''));
    }

    /**
     * "Desa X / Kecamatan Y" -- kolom gabungan (permintaan user), pakai nama CANONICAL hasil
     * WilayahResolver kalau sudah resolve (prioritas sama dengan resources/views/pdf/patients-
     * export.blade.php), fallback ke teks mentah SiLAKES (kel_desa_raw/kecamatan_raw) kalau
     * belum -- supaya baris tetap informatif, bukan kosong, walau wilayahnya belum ter-resolve.
     */
    public function kelurahanKecamatan(PatientsCache $patient): string
    {
        $desa = $patient->desa?->nama ?? $patient->kel_desa_raw ?? '-';
        $kecamatan = $patient->kecamatan?->nama ?? $patient->kecamatan_raw ?? '-';

        return "{$desa} / {$kecamatan}";
    }
}
