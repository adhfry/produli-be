<?php

namespace App\Services\Patient;

use App\Models\LabResultCache;
use App\Models\PatientsCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Logika bersama tabel "Download Hasil" (dashboard/pasien, permintaan user 2026-09-01) --
 * pasien terfilter dipivot jadi 1 baris per pasien dengan kolom DINAMIS per parameter
 * pemeriksaan (GDP/CHOLESTEROL/TRIGLISERIDA/dst). Dipakai KEDUA jalur ekspor
 * (PatientController::exportHasilPdf() dan App\Exports\PatientsHasilExport untuk Excel) supaya
 * nilai yang ditampilkan tidak pernah drift antar format.
 */
class HasilPemeriksaanExportService
{
    /**
     * Daftar nama parameter unik (jadi kolom dinamis tabel) dari SELURUH pasien yang match
     * filter -- subquery ke query pasien yang sudah difilter (bukan pluck ID lalu whereIn
     * array PHP) supaya tetap murah walau jumlah pasien yang match sangat banyak.
     *
     * @param  Builder<PatientsCache>  $filteredPatientQuery
     * @return array<int, string>
     */
    public function resolveParameters(Builder $filteredPatientQuery): array
    {
        return LabResultCache::query()
            ->whereIn('patient_id', (clone $filteredPatientQuery)->select('patients_cache.external_patient_id'))
            ->distinct()
            ->orderBy('parameter')
            ->pluck('parameter')
            ->values()
            ->all();
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
    public function latestPerParameter(Collection $labResults): Collection
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
     * diperiksa parameter tsb sama sekali (kolom dinamis dibentuk dari GABUNGAN semua pasien
     * yang match filter, jadi wajar banyak sel kosong per pasien).
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
