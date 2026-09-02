<?php

namespace App\Services\Patient;

use App\Models\LabResultCache;
use App\Models\PatientsCache;
use App\Services\Risk\LabParameterReferenceService;
use App\Support\NikDisplay;
use Illuminate\Support\Collection;

/**
 * Logika bersama tabel "Download Hasil" (dashboard/pasien, permintaan user 2026-09-01) --
 * pasien terfilter dipivot jadi 1 baris per pasien dengan kolom TETAP per parameter
 * pemeriksaan. Dipakai KEDUA jalur ekspor (PatientController::exportHasilPdf() dan
 * App\Exports\PatientsHasilExport untuk Excel) supaya nilai yang ditampilkan (termasuk
 * highlight "merah"/abnormal, permintaan user 2026-09-02) tidak pernah drift antar format.
 */
class HasilPemeriksaanExportService
{
    public function __construct(
        private readonly LabParameterReferenceService $labParameterReference,
    ) {}

    /**
     * Kolom TETAP & URUT kiri-ke-kanan (permintaan user 2026-09-02) -- BUKAN lagi digali
     * dinamis dari data yang match filter. Key = nama PERSIS kolom lab_results_cache.parameter
     * dari SiLAKES asli (SUMBER SAMA dengan RiskClassificationService::BERAT_PARAMETERS/
     * SEDANG_PARAMETERS, dikonfirmasi ulang dari data sync nyata produksi 2026-09-02) -- value
     * = label kolom yang ditampilkan (mis. raw "Gula Darah Puasa" ditampilkan sbg singkatan
     * "GDP", permintaan user). Pasien yang tidak punya hasil parameter tsb (mis. pasien
     * Hipertensi tanpa GDP/HbA1c) TETAP dapat kolomnya, cuma isinya "-" (lihat cellData()) --
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
     * @return array<string, array{value: ?string, satuan: ?string, bn: ?string, abnormal: bool}>
     */
    public function rowCellData(Collection $labResults): array
    {
        $latest = $this->latestPerParameter($labResults);

        $data = [];
        foreach (self::PARAMETER_COLUMNS as $rawParameter => $label) {
            $data[$label] = $this->cellData($latest->get($rawParameter));
        }

        return $data;
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
     * Data 1 sel kolom parameter -- nilai/satuan/BN (batas normal = nilai_rujukan ASLI dari
     * SiLAKES, permintaan user) dipisah (bukan digabung 1 string) supaya caller (blade/Excel)
     * bisa mewarnai HANYA bagian nilai hasil kalau abnormal, nilai_rujukan & satuan tetap
     * hitam. value null = pasien ini belum pernah diperiksa parameter tsb sama sekali (mis.
     * pasien Hipertensi murni tanpa GDP/HbA1c) -- caller merender ini sbg "-", bukan baris BN
     * kosong menggantung.
     *
     * @return array{value: ?string, satuan: ?string, bn: ?string, abnormal: bool}
     */
    private function cellData(?LabResultCache $result): array
    {
        if ($result === null) {
            return ['value' => null, 'satuan' => null, 'bn' => null, 'abnormal' => false];
        }

        return [
            'value' => $result->value,
            'satuan' => $result->satuan,
            'bn' => $result->nilai_rujukan,
            'abnormal' => $this->isAbnormal($result),
        ];
    }

    /**
     * "Merah" (permintaan user) = hasil DI LUAR ambang -- PRIORITASKAN ambang RESMI PRODULI
     * (risk_thresholds via LabParameterReferenceService, SUMBER SAMA dgn indikator "Tren Hasil
     * Pemeriksaan" di detail pasien) untuk 6 parameter yang terkonfigurasi (Gula Darah Puasa,
     * Cholesterol, Trigliserida, LDL, Urea, Creatinine) -- supaya highlight merah di laporan
     * ini TIDAK PERNAH beda pendapat dgn indikator yang sudah tampil ke user di tempat lain.
     *
     * HDL/HbA1c/Microalbumin TIDAK punya risk_thresholds aktif sama sekali (keputusan eksplisit
     * sebelumnya, lihat docblock LabParameterReferenceService) -- fallback baca APA ADANYA dari
     * teks nilai_rujukan SiLAKES sendiri (exceedsSimpleTextualBound()), HANYA pola batas TUNGGAL
     * sederhana ("< 30", "<= 6.5"). Pola majemuk (rentang gender/umur, mis. format asli Urea/
     * Creatinine "L:0.8-1.3 | P:0.6-1.2") SENGAJA TIDAK dicoba ditebak di jalur fallback ini --
     * parameter itu toh sudah tercover ambang resmi di atas duluan, jadi fallback tidak pernah
     * benar-benar dipakai untuknya. Lebih baik tidak menandai merah sama sekali daripada salah
     * tandai dari parsing teks bebas yang keliru pada laporan yang dipakai staf kesehatan.
     */
    private function isAbnormal(LabResultCache $result): bool
    {
        if (! is_numeric($result->value)) {
            return false;
        }

        $value = (float) $result->value;

        $reference = $this->labParameterReference->evaluate($result->parameter, $value);
        if ($reference['zone'] !== null) {
            return $reference['zone'] !== 'normal';
        }

        return $this->exceedsSimpleTextualBound($result->nilai_rujukan, $value);
    }

    /**
     * Parser SANGAT terbatas -- HANYA pola batas tunggal "< N", "<= N", "> N", ">= N", atau
     * rentang sederhana "N-M" TANPA embel-embel gender/umur. Format apa pun di luar itu (ada
     * "|", "L:"/"P:", "th", dst) dianggap TIDAK DIKENALI -- balikin false (tidak merah), bukan
     * menebak, karena salah tandai lebih berbahaya daripada tidak menandai pada laporan medis.
     */
    private function exceedsSimpleTextualBound(?string $nilaiRujukan, float $value): bool
    {
        if ($nilaiRujukan === null) {
            return false;
        }

        $text = trim($nilaiRujukan);

        if (preg_match('/^<=\s*([\d.]+)$/', $text, $m)) {
            return $value > (float) $m[1];
        }
        if (preg_match('/^<\s*([\d.]+)$/', $text, $m)) {
            return $value >= (float) $m[1];
        }
        if (preg_match('/^>=\s*([\d.]+)$/', $text, $m)) {
            return $value < (float) $m[1];
        }
        if (preg_match('/^>\s*([\d.]+)$/', $text, $m)) {
            return $value <= (float) $m[1];
        }
        if (preg_match('/^([\d.]+)\s*-\s*([\d.]+)$/', $text, $m)) {
            return $value < (float) $m[1] || $value > (float) $m[2];
        }

        return false;
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

    /**
     * Nama FKTP (puskesmas) pengirim/binaan pasien ini -- kolom "FKTP" (permintaan user
     * 2026-09-02, menggantikan kolom NIK -- NIK sendiri pindah jadi sub-teks di bawah nama,
     * lihat nikSubline()). SAMA PERSIS prioritas puskesmasCellLabel() di dashboard/pasien/
     * index.vue & resources/views/pdf/patients-export.blade.php (jangan sampai drift 3
     * tempat): puskesmas yang SUDAH resolve dulu, fallback nama pengirim PERORANGAN (dokter/
     * bidan, bukan institusi puskesmas) kalau itu jelas dari SiLAKES, terakhir "Belum
     * Teridentifikasi" -- BUKAN dikosongkan, datanya memang belum ter-resolve.
     */
    public function fktpLabel(PatientsCache $patient): string
    {
        if ($patient->puskesmas?->nama) {
            return $patient->puskesmas->nama;
        }
        if ($patient->puskesmas_resolution_method === 'pengirim_individual' && $patient->pengirim_raw) {
            return "Rujukan: {$patient->pengirim_raw}";
        }

        return 'Belum Teridentifikasi';
    }

    /**
     * Teks kecil NIK di bawah nama pasien (permintaan user 2026-09-02 -- sebelumnya kolom
     * sendiri, sekarang pindah jadi sub-teks). Sumber pengungkapan NIK TETAP SAMA
     * App\Support\NikDisplay (kode wilayah Sumenep 3529, kebijakan Kepala Dinas) -- cuma
     * tempat tampilnya yang berubah. italic=true HANYA saat NIK tidak diketahui/tidak valid,
     * teksnya pun berubah jadi "NIK Tidak Diketahui" (bukan "NIK: Tidak Diketahui") sesuai
     * permintaan.
     *
     * @return array{text: string, italic: bool}
     */
    public function nikSubline(PatientsCache $patient): array
    {
        $resolved = NikDisplay::resolve($patient->nik);

        if ($resolved === 'Tidak Diketahui') {
            return ['text' => 'NIK Tidak Diketahui', 'italic' => true];
        }

        return ['text' => "NIK: {$resolved}", 'italic' => false];
    }
}
