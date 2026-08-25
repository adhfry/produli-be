<?php

namespace Tests\Feature\Risk;

use App\Models\LabResultCache;
use App\Models\PatientsCache;
use App\Models\RiskClassification;
use App\Models\RiskThreshold;
use App\Services\Risk\RiskClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Regresi untuk RiskClassificationService (docs/planning/02 §3, kriteria REVISI KEDUA --
 * laporan bug A. JAZILI, Trigliserida 192 SENDIRIAN salah naik ke Sedang):
 * - Berat: KELIMA parameter kombinasi (Gula Darah Puasa, Cholesterol, Trigliserida, LDL,
 *   Urea) harus LENGKAP tersedia DAN semuanya melebihi nilai rujukan sekaligus.
 * - Sedang: pola IDENTIK dengan Berat -- KEEMPAT parameter (Gula Darah Puasa, Cholesterol,
 *   Trigliserida, LDL) harus LENGKAP tersedia DAN semuanya melebihi nilai rujukan sekaligus.
 *   BUKAN LAGI "salah satu dari 4 parameter melebihi" (OR) -- itu bug A. Jazili.
 * - Ringan (REVISI KETIGA, dikembalikan sesuai standar landing page): KHUSUS Gula Darah Puasa
 *   melebihi ambang sendirian -- parameter kombinasi lain (mis. "cuma Trigliserida melebihi")
 *   TETAP tidak menghasilkan apa pun, bug A. Jazili tidak kembali.
 *
 * REVISI Bu Kadis (lihat juga docblock RiskClassificationService): Creatinine keluar dari
 * kombinasi di atas, jadi "direct classifier" bertingkat (1.7-1.9=Sedang, >=2.0=Berat) yang
 * bisa menentukan level SENDIRIAN, independen dari kombinasi 4/5-parameter. Level akhir =
 * paling parah antara jalur kombinasi dan jalur direct-classifier. Tier 'tidak_berisiko'
 * ditulis begitu tidak ada kriteria yang match -- baik evaluasi PERTAMA kalinya (REVISI
 * KEEMPAT, audit "852 pasien hilang") maupun pasien yang PERNAH diklasifikasi lalu membaik.
 *
 * Nama parameter di sini HARUS persis sama dengan kolom lab_results_cache.parameter dari
 * SiLAKES asli -- "GDP" (singkatan medis umum) TIDAK PERNAH muncul di data nyata, cuma
 * "Gula Darah Puasa". Salah satu bug produksi nyata yang pernah bikin patients_classified
 * selalu 0 meski ribuan hasil lab sudah tersinkron -- lihat riwayat percakapan.
 *
 * Termasuk regresi khusus untuk kekhawatiran "delta sync tidak menjamin urutan kronologis"
 * (docs/planning/02 §3) yang sempat ditanyakan user -- dipindah pakai Creatinine (direct
 * classifier, bisa berdiri sendiri) alih-alih Gula Darah Puasa sendirian sejak Gula Darah
 * Puasa sendirian sudah tidak lagi menghasilkan klasifikasi apa pun (lihat revisi di atas).
 */
class RiskClassificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private RiskClassificationService $service;

    private PatientsCache $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(RiskClassificationService::class);

        $this->patient = PatientsCache::create([
            'external_patient_id' => 999001,
            'nik_hash' => 'HASH-999001',
            'nama' => 'Pasien Uji',
            'wilayah_status' => 'unknown',
        ]);

        RiskThreshold::insert([
            ['parameter' => 'Gula Darah Puasa', 'level' => 'sedang', 'operator' => '>', 'is_direct_classifier' => false, 'threshold_min' => 120, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'Gula Darah Puasa', 'level' => 'berat', 'operator' => '>', 'is_direct_classifier' => false, 'threshold_min' => 200, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'Cholesterol', 'level' => 'sedang', 'operator' => '>', 'is_direct_classifier' => false, 'threshold_min' => 200, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'Trigliserida', 'level' => 'sedang', 'operator' => '>', 'is_direct_classifier' => false, 'threshold_min' => 150, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'LDL', 'level' => 'sedang', 'operator' => '>', 'is_direct_classifier' => false, 'threshold_min' => 130, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'Urea', 'level' => 'sedang', 'operator' => '>', 'is_direct_classifier' => false, 'threshold_min' => 40, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            // Creatinine (revisi Bu Kadis): direct classifier bertingkat, bisa langsung
            // menentukan level sendirian tanpa perlu parameter lain ikut melebihi.
            ['parameter' => 'Creatinine', 'level' => 'sedang', 'operator' => 'between', 'is_direct_classifier' => true, 'threshold_min' => 1.7, 'threshold_max' => 1.9, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'Creatinine', 'level' => 'berat', 'operator' => '>=', 'is_direct_classifier' => true, 'threshold_min' => 2.0, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function addLabResult(int $externalId, string $parameter, string $value, string $tanggal, ?string $syncedAt = null): LabResultCache
    {
        return LabResultCache::create([
            'external_id' => $externalId,
            'patient_id' => $this->patient->external_patient_id,
            'parameter' => $parameter,
            'value' => $value,
            'tanggal_periksa' => $tanggal,
            'synced_at' => $syncedAt ?? $tanggal,
        ]);
    }

    /**
     * Isi panel lengkap keenam parameter dengan nilai NORMAL (di bawah rujukan) sebagai
     * default, lalu timpa sebagian lewat $overrides -- memudahkan menyusun skenario
     * "semua tersedia, sebagian/semua melebihi" tanpa mengetik 6 addLabResult() manual tiap tes.
     *
     * @param  array<string, string>  $overrides
     */
    private function addFullPanel(array $overrides = [], string $tanggal = '2026-07-20'): void
    {
        $values = array_merge([
            'Gula Darah Puasa' => '90',
            'Creatinine' => '1.0',
            'Cholesterol' => '180',
            'Trigliserida' => '100',
            'LDL' => '100',
            'Urea' => '30',
        ], $overrides);

        $id = 600000;

        foreach ($values as $parameter => $value) {
            $this->addLabResult($id++, $parameter, $value, $tanggal);
        }
    }

    public function test_semua_lima_parameter_kombinasi_tersedia_dan_melebihi_menghasilkan_berat(): void
    {
        $this->addFullPanel([
            'Gula Darah Puasa' => '250',
            'Creatinine' => '2.0',
            'Cholesterol' => '300',
            'Trigliserida' => '200',
            'LDL' => '180',
            'Urea' => '60',
        ]);

        $result = $this->service->classify($this->patient->fresh());

        // Berat lewat DUA jalur sekaligus di sini: kombinasi 5-parameter (GDP/Chol/Trig/LDL/
        // Urea semua melebihi) DAN direct-classifier Creatinine (2.0 >= 2.0) -- keduanya
        // sepakat 'berat', jadi hasil akhirnya tetap 'berat' (lihat test standalone di bawah
        // untuk kasus direct-classifier SENDIRIAN tanpa kombinasi lengkap).
        $this->assertSame('berat', $result->level);
    }

    public function test_keempat_parameter_sedang_lengkap_dan_melebihi_menghasilkan_sedang(): void
    {
        // Kebalikan Berat: PERSIS keempat SEDANG_PARAMETERS (GDP/Cholesterol/Trigliserida/LDL)
        // lengkap tersedia DAN semuanya melebihi ambang -- Urea sengaja dibiarkan normal
        // (kalau Urea ikut melebihi + Creatinine juga, itu sudah masuk kasus Berat, bukan tes ini).
        $this->addFullPanel([
            'Gula Darah Puasa' => '150',
            'Cholesterol' => '250',
            'Trigliserida' => '180',
            'LDL' => '160',
        ]);

        $result = $this->service->classify($this->patient->fresh());

        $this->assertSame('sedang', $result->level);
    }

    public function test_trigliserida_sendirian_tidak_menghasilkan_sedang_bug_a_jazili(): void
    {
        // Regresi persis laporan bug: pasien A. Jazili, Trigliserida 192 (ambang >140) SATU-
        // SATUNYA yang melebihi -- Cholesterol/LDL normal, Gula Darah Puasa TIDAK PERNAH
        // diperiksa sama sekali (bukan "tersedia tapi normal"). Sebelum perbaikan ini, satu
        // parameter saja cukup untuk salah naik ke Sedang -- sekarang harus TIDAK naik ke
        // Sedang, cuma 'tidak_berisiko' (REVISI KEEMPAT: evaluasi pertama tanpa kriteria yang
        // match tetap ditulis, bukan lagi dibiarkan tanpa baris sama sekali).
        $this->addLabResult(500001, 'Cholesterol', '181', '2026-05-08');
        $this->addLabResult(500002, 'LDL', '75', '2026-05-08');
        $this->addLabResult(500003, 'Trigliserida', '192', '2026-05-08');
        $this->addLabResult(500004, 'Urea', '30', '2026-05-08');
        $this->addLabResult(500005, 'Creatinine', '0.6', '2026-05-08');

        $result = $this->service->classify($this->patient->fresh());

        $this->assertNotNull($result);
        $this->assertSame('tidak_berisiko', $result->level);
        $this->assertSame(1, RiskClassification::where('patient_id', $this->patient->id)->count());
    }

    public function test_creatinine_1_8_sendirian_menghasilkan_sedang_lewat_direct_classifier(): void
    {
        // HANYA Creatinine yang diperiksa, tidak ada parameter lain sama sekali -- kombinasi
        // 5-parameter jelas tidak match (availableParameters cuma 1), tapi direct-classifier
        // tidak butuh parameter lain sama sekali.
        $this->addLabResult(500001, 'Creatinine', '1.8', '2026-07-20');

        $result = $this->service->classify($this->patient->fresh());

        $this->assertSame('sedang', $result->level);
        $this->assertSame('Creatinine', $result->criteria_snapshot[0]['parameter']);
    }

    public function test_creatinine_2_1_sendirian_menghasilkan_berat_lewat_direct_classifier(): void
    {
        $this->addLabResult(500001, 'Creatinine', '2.1', '2026-07-20');

        $result = $this->service->classify($this->patient->fresh());

        $this->assertSame('berat', $result->level);
    }

    public function test_creatinine_1_6_di_bawah_ambang_sedang_tidak_match(): void
    {
        $this->addLabResult(500001, 'Creatinine', '1.6', '2026-07-20');

        $result = $this->service->classify($this->patient->fresh());

        $this->assertNotNull($result);
        $this->assertSame('tidak_berisiko', $result->level);
    }

    public function test_creatinine_berat_menang_walau_kombinasi_lain_cuma_sedang(): void
    {
        // Creatinine 2.1 (direct-classifier Berat) berdiri sendiri, parameter lain hanya
        // Cholesterol yang melebihi (GDP/Trigliserida/LDL normal, jadi bukan Sedang ATAUPUN
        // Berat lewat jalur kombinasi -- keduanya butuh AND penuh sekarang) -- level akhir
        // harus ambil yang TERPARAH (Berat) dari jalur direct-classifier, bukan diam di null.
        $this->addFullPanel([
            'Creatinine' => '2.1',
            'Cholesterol' => '300',
        ]);

        $result = $this->service->classify($this->patient->fresh());

        $this->assertSame('berat', $result->level);
    }

    public function test_gula_darah_puasa_normal_gagalkan_kriteria_sedang_maupun_berat_jadi_tidak_berisiko(): void
    {
        // Gula Darah Puasa sengaja dibiarkan normal -- 4 dari 5 parameter kombinasi Berat
        // melebihi (Cholesterol/Trigliserida/LDL/Urea), tapi karena Gula Darah Puasa TERSEDIA
        // dan TIDAK melebihi, ini gagal AND penuh untuk Berat MAUPUN Sedang (SEDANG_PARAMETERS
        // juga mewajibkan Gula Darah Puasa ikut melebihi sejak revisi AND ketat) -- bukan lagi
        // otomatis jatuh ke Sedang seperti perilaku lama (OR), dan sejak REVISI KEEMPAT tidak
        // lagi dibiarkan tanpa baris sama sekali -- 'tidak_berisiko'.
        $this->addFullPanel([
            'Cholesterol' => '300',
            'Trigliserida' => '200',
            'LDL' => '180',
            'Urea' => '60',
        ]);

        $result = $this->service->classify($this->patient->fresh());

        $this->assertNotNull($result);
        $this->assertSame('tidak_berisiko', $result->level);
    }

    public function test_hanya_gula_darah_puasa_tersedia_dan_melebihi_menghasilkan_ringan(): void
    {
        // Kasus yang sengaja diklarifikasi: parameter lain belum PERNAH diperiksa sama sekali
        // (bukan "tersedia tapi normal") -- AND penuh untuk Sedang gagal karena kelengkapan
        // keempat parameter tidak terpenuhi, tapi Gula Darah Puasa sendirian yang tinggi tetap
        // menghasilkan tier Ringan (REVISI KETIGA, standar landing page -- lihat docblock kelas).
        $this->addLabResult(500001, 'Gula Darah Puasa', '250', '2026-07-20');

        $result = $this->service->classify($this->patient->fresh());

        $this->assertNotNull($result);
        $this->assertSame('ringan', $result->level);
    }

    public function test_gula_darah_puasa_dan_kolesterol_saja_tanpa_parameter_lain_menghasilkan_ringan(): void
    {
        // Cuma 2 dari 4 SEDANG_PARAMETERS yang PERNAH diperiksa (Trigliserida/LDL tidak
        // pernah ada hasil labnya sama sekali) -- AND penuh Sedang gagal di tahap ketersediaan,
        // tapi Ringan cuma peduli Gula Darah Puasa sendiri (bukan kelengkapan kombinasi), jadi
        // tetap 'ringan' di sini walau Cholesterol turut melebihi.
        $this->addLabResult(500001, 'Gula Darah Puasa', '250', '2026-07-20');
        $this->addLabResult(500002, 'Cholesterol', '300', '2026-07-20');

        $result = $this->service->classify($this->patient->fresh());

        $this->assertNotNull($result);
        $this->assertSame('ringan', $result->level);
    }

    public function test_gula_darah_puasa_melebihi_dan_nilai_non_numerik_di_skip_dengan_log(): void
    {
        Log::spy();

        // Cholesterol 'ABNORMAL' di-skip (non-numerik) -- itu artinya Cholesterol dianggap
        // TIDAK TERSEDIA sama sekali, jadi kombinasi Sedang/Berat otomatis gagal di tahap
        // ketersediaan walau Gula Darah Puasa sendiri melebihi. Creatinine 1.8 ditambahkan
        // supaya tes ini tetap punya hasil klasifikasi non-null untuk diperiksa (lewat jalur
        // direct-classifier yang independen dari kombinasi), tanpa mengubah esensi yang diuji:
        // nilai non-numerik di-skip + dicatat log warning.
        $this->addLabResult(500001, 'Gula Darah Puasa', '250', '2026-07-20');
        $this->addLabResult(500002, 'Cholesterol', 'ABNORMAL', '2026-07-20');
        $this->addLabResult(500003, 'Creatinine', '1.8', '2026-07-20');

        $result = $this->service->classify($this->patient->fresh());

        $this->assertNotNull($result);
        $this->assertSame('sedang', $result->level); // lewat direct-classifier Creatinine, bukan kombinasi
        // GDP 250 match 2 baris threshold (sedang >120 DAN berat >200) + Creatinine 1.8 match
        // 1 baris (sedang, between 1.7-1.9) = 3 baris kriteria total.
        $this->assertCount(3, $result->criteria_snapshot);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_perubahan_nilai_membuat_baris_baru_dan_memindahkan_is_latest(): void
    {
        // Creatinine SENGAJA dibiarkan normal (default panel 1.0) supaya kasus ini murni
        // menguji jalur kombinasi 5-parameter, tanpa jalur direct-classifier ikut memicu Berat.
        $this->addFullPanel([
            'Gula Darah Puasa' => '250',
            'Cholesterol' => '300',
            'Trigliserida' => '200',
            'LDL' => '180',
            'Urea' => '60',
        ]);
        $first = $this->service->classify($this->patient->fresh());

        // Gula Darah Puasa turun ke normal -> AND penuh Berat MAUPUN Sedang gagal (keduanya
        // sekarang mewajibkan Gula Darah Puasa ikut melebihi) -- pasien sudah pernah
        // diklasifikasi sebelumnya, jadi ini ditulis sebagai 'tidak_berisiko' (membaik),
        // BUKAN 'sedang' seperti perilaku lama (OR).
        LabResultCache::where('patient_id', $this->patient->external_patient_id)
            ->where('parameter', 'Gula Darah Puasa')
            ->update(['value' => '90']);
        $second = $this->service->classify($this->patient->fresh());

        $this->assertSame('berat', $first->level);
        $this->assertSame('tidak_berisiko', $second->level);
        $this->assertSame(2, RiskClassification::where('patient_id', $this->patient->id)->count());
        $this->assertSame(1, RiskClassification::where('patient_id', $this->patient->id)->where('is_latest', true)->count());
        $this->assertFalse($first->fresh()->is_latest);
        $this->assertTrue($second->fresh()->is_latest);
    }

    public function test_classify_dipanggil_ulang_tanpa_perubahan_data_tidak_menulis_baris_duplikat(): void
    {
        // Bug nyata yang diperbaiki: classify() dulu SELALU menulis baris baru tiap dipanggil,
        // termasuk saat data lab sama sekali tidak berubah (mis. delta sync melihat "ada data
        // baru" padahal cuma updated_at yang bergeser, atau produli:reclassify-risk dijalankan
        // ulang manual) -- riwayat jadi penuh baris duplikat identik, cuma computed_at beda.
        // Pakai kombinasi 4-parameter penuh (bukan Gula Darah Puasa sendirian) supaya panggilan
        // pertama benar-benar menghasilkan baris untuk diuji idempotensinya.
        $this->addFullPanel([
            'Gula Darah Puasa' => '250',
            'Cholesterol' => '300',
            'Trigliserida' => '200',
            'LDL' => '180',
        ]);
        $first = $this->service->classify($this->patient->fresh());

        $second = $this->service->classify($this->patient->fresh());

        $this->assertSame('sedang', $first->level);
        $this->assertNull($second, 'Panggilan kedua tanpa perubahan data harus no-op (null), bukan baris baru.');
        $this->assertSame(1, RiskClassification::where('patient_id', $this->patient->id)->count());
    }

    public function test_early_detection_flag_berubah_tetap_menulis_baris_baru_walau_level_dan_kriteria_sama(): void
    {
        // KEJADIAN NYATA (temuan produksi): baris is_latest lama bisa saja level+criteria-nya
        // SAMA PERSIS dengan hasil hitung ulang, TAPI early_detection_flag hasil hitung ulang
        // berbeda (mis. baris lama dari sebelum Smart Early Detection dihitung benar) -- guard
        // idempotensi SEBELUM perbaikan ini cuma bandingkan level+criteria, jadi flag yang
        // seharusnya berubah jadi tidak pernah ter-update SELAMANYA lewat reclassify-risk
        // (guard selalu bilang "tidak berubah", padahal early_detection_flag-nya berubah).
        $this->addLabResult(500001, 'Creatinine', '1.89', '2026-07-20');
        $first = $this->service->classify($this->patient->fresh());
        $this->assertSame('sedang', $first->level);
        $this->assertTrue($first->early_detection_flag, 'Proximity 63.3% harus ter-flag (baseline test ini).');

        // Simulasi baris lama/stale: level+criteria TETAP sama seperti $first (tidak disentuh),
        // cuma early_detection_flag-nya dipaksa false -- langsung ke DB, BUKAN lewat classify(),
        // supaya murni menguji guard idempotensi classify() berikutnya, bukan logika hitungnya.
        $first->update(['early_detection_flag' => false, 'early_detection_reason' => null]);

        $second = $this->service->classify($this->patient->fresh());

        $this->assertNotNull($second, 'early_detection_flag berbeda dari yang tersimpan HARUS tetap menulis baris baru, bukan null.');
        $this->assertSame('sedang', $second->level);
        $this->assertTrue($second->early_detection_flag);
        $this->assertSame(2, RiskClassification::where('patient_id', $this->patient->id)->count());
        $this->assertTrue($second->fresh()->is_latest);
    }

    public function test_classify_ulang_dengan_nilai_berubah_tetap_menulis_baris_baru(): void
    {
        // Kebalikan dari test di atas -- guard idempotensi TIDAK BOLEH menghalangi perubahan
        // kondisi pasien yang sungguhan.
        $this->addFullPanel([
            'Gula Darah Puasa' => '250',
            'Cholesterol' => '300',
            'Trigliserida' => '200',
            'LDL' => '180',
        ]);
        $this->service->classify($this->patient->fresh());

        LabResultCache::where('patient_id', $this->patient->external_patient_id)
            ->where('parameter', 'Gula Darah Puasa')
            ->update(['value' => '260']);
        $second = $this->service->classify($this->patient->fresh());

        $this->assertNotNull($second);
        $this->assertSame(2, RiskClassification::where('patient_id', $this->patient->id)->count());
    }

    public function test_belum_pernah_diklasifikasi_dan_tidak_ada_yang_match_menulis_tidak_berisiko(): void
    {
        // REVISI KEEMPAT (audit "852 pasien hilang") -- pasien belum PERNAH punya baris
        // risk_classifications sama sekali, tidak ada parameter yang melebihi rujukan: SEKARANG
        // tetap ditulis 'tidak_berisiko' (bukan lagi dibiarkan tanpa baris sama sekali), supaya
        // total_patients (pasien yang punya baris efektif) selalu = total_patients_prolanis
        // (semua baris patients_cache), dan pasien ini tidak hilang dari agregat mana pun yang
        // JOIN dari risk_classifications (peta per kecamatan/desa/puskesmas, filter
        // ?risk_level=tidak_berisiko, export PDF).
        $this->addLabResult(500001, 'Gula Darah Puasa', '80', '2026-07-20');

        $result = $this->service->classify($this->patient->fresh());

        $this->assertNotNull($result);
        $this->assertSame('tidak_berisiko', $result->level);
        $this->assertSame(1, RiskClassification::where('patient_id', $this->patient->id)->count());
    }

    public function test_membaik_dari_pernah_diklasifikasi_menjadi_tidak_ada_yang_match_menulis_tidak_berisiko(): void
    {
        // Pasien PERNAH divonis Sedang (kombinasi 4-parameter penuh), lalu SELURUH nilainya
        // kembali normal -- revisi Bu Kadis: ini harus ditulis sebagai baris baru
        // 'tidak_berisiko' (pasien membaik total), bukan diam-diam tetap 'sedang' selamanya
        // di is_latest.
        $this->addFullPanel([
            'Gula Darah Puasa' => '250',
            'Cholesterol' => '300',
            'Trigliserida' => '200',
            'LDL' => '180',
        ]);
        $first = $this->service->classify($this->patient->fresh());

        LabResultCache::where('patient_id', $this->patient->external_patient_id)
            ->whereIn('parameter', ['Gula Darah Puasa', 'Cholesterol', 'Trigliserida', 'LDL'])
            ->update(['value' => '1']); // jauh di bawah ambang manapun, "1" berlaku utk semua kolom ini
        $second = $this->service->classify($this->patient->fresh());

        $this->assertSame('sedang', $first->level);
        $this->assertNotNull($second);
        $this->assertSame('tidak_berisiko', $second->level);
        $this->assertSame(2, RiskClassification::where('patient_id', $this->patient->id)->count());
        $this->assertFalse($first->fresh()->is_latest);
        $this->assertTrue($second->fresh()->is_latest);
    }

    public function test_klasifikasi_pakai_tanggal_periksa_terbaru_bukan_urutan_insert_atau_synced_at(): void
    {
        // synced_at sengaja DIBALIK dari tanggal_periksa: hasil lama (tanggal jauh, nilai
        // normal) baru "disinkron" HARI INI, hasil baru (tanggal dekat, nilai direct-classifier
        // Sedang) justru sudah lama tersinkron -- meniru delta sync yang tidak berurutan
        // kronologis (docs/planning/02 §3). Pakai Creatinine (direct-classifier, berdiri
        // sendiri) alih-alih Gula Darah Puasa sendirian -- Gula Darah Puasa sendirian sudah
        // tidak lagi menghasilkan klasifikasi apa pun sejak revisi AND ketat, jadi tidak lagi
        // bisa dipakai menguji urutan tanggal ini. Kalau kode salah urut pakai synced_at/
        // insert order, hasilnya akan salah pilih 1.0 (normal, tidak match apa pun) alih-alih
        // 1.8 (sedang, lewat direct-classifier).
        $this->addLabResult(500002, 'Creatinine', '1.0', '2026-01-10', now()->toDateTimeString());
        $this->addLabResult(500001, 'Creatinine', '1.8', '2026-07-20', '2026-01-01 00:00:00');

        $result = $this->service->classify($this->patient->fresh());

        $this->assertNotNull($result, 'Harusnya tetap match dari tanggal_periksa 2026-07-20, bukan NULL');
        $this->assertSame('sedang', $result->level);
    }

    public function test_tiebreak_synced_at_saat_tanggal_periksa_sama_persis(): void
    {
        $this->addLabResult(500001, 'Creatinine', '1.0', '2026-07-20', '2026-07-20 08:00:00');
        $this->addLabResult(500002, 'Creatinine', '1.8', '2026-07-20', '2026-07-20 09:00:00');

        $result = $this->service->classify($this->patient->fresh());

        // synced_at lebih baru (retest di hari yang sama) yang menang -> 1.8 -> sedang (direct-classifier).
        $this->assertSame('sedang', $result->level);
    }

    public function test_early_detection_flag_proximity_saat_creatinine_mendekati_ambang_berat(): void
    {
        // Band Creatinine sedang = 1.7-1.9, tier Berat berikutnya = 2.0 -> proximity 1.89 =
        // (1.89-1.7)/(2.0-1.7) = 63.3%, di atas PRODULI_EARLY_DETECTION_PROXIMITY_THRESHOLD=0.6.
        $this->addLabResult(500001, 'Creatinine', '1.89', '2026-07-20');

        $result = $this->service->classify($this->patient->fresh());

        $this->assertSame('sedang', $result->level);
        $this->assertTrue($result->early_detection_flag);
        $this->assertNotNull($result->early_detection_reason);
        $proximityReasons = collect($result->early_detection_reason)->where('type', 'proximity');
        $this->assertCount(1, $proximityReasons);
        $this->assertSame('Creatinine', $proximityReasons->first()['parameter']);
    }

    public function test_early_detection_flag_false_saat_creatinine_masih_jauh_dari_ambang_berat(): void
    {
        // Proximity 1.72 = (1.72-1.7)/0.3 = 6.7%, jauh di bawah ambang 60%.
        $this->addLabResult(500001, 'Creatinine', '1.72', '2026-07-20');

        $result = $this->service->classify($this->patient->fresh());

        $this->assertSame('sedang', $result->level);
        $this->assertFalse($result->early_detection_flag);
        $this->assertNull($result->early_detection_reason);
    }

    public function test_early_detection_flag_combo_breadth_saat_4_dari_5_parameter_kombinasi_melebihi_dengan_margin_tinggi(): void
    {
        // Revisi: combo_breadth sekarang berbasis MARGIN persentase DAN mewajibkan Gula Darah
        // Puasa/LDL/Trigliserida (combo_required_parameters) ikut exceeded -- Urea sengaja
        // dibiarkan normal (Creatinine juga normal), 4 dari 5 BERAT_PARAMETERS (GDP, Cholesterol,
        // Trigliserida, LDL) melebihi ambang SAMA-SAMA 60% (di atas
        // PRODULI_EARLY_DETECTION_COMBO_MARGIN_THRESHOLD_PERCENT=50), bukan cuma "exceeded".
        // Catatan: keempat parameter ini PERSIS SEDANG_PARAMETERS, jadi level akhirnya 'sedang'
        // lewat jalur kombinasi AND penuh yang baru juga (bukan cuma dari early detection).
        $this->addFullPanel([
            'Gula Darah Puasa' => '192', // ambang 120 -> margin 60%
            'Cholesterol' => '320',   // ambang 200 -> margin 60%
            'Trigliserida' => '240',  // ambang 150 -> margin 60%
            'LDL' => '208',           // ambang 130 -> margin 60%
        ]);

        $result = $this->service->classify($this->patient->fresh());

        $this->assertSame('sedang', $result->level);
        $this->assertTrue($result->early_detection_flag);
        $comboReasons = collect($result->early_detection_reason)->where('type', 'combo_breadth');
        $this->assertCount(1, $comboReasons);
        $this->assertSame(['Urea'], $comboReasons->first()['missing_parameters']);
        $this->assertSame(60, $comboReasons->first()['average_margin_percent']);
    }

    public function test_early_detection_flag_false_saat_4_dari_5_exceeded_tapi_margin_rendah(): void
    {
        // Regresi bug lama: 4 dari 5 parameter kombinasi exceeded TAPI cuma sedikit di atas
        // ambang (margin jauh di bawah 50%) -- dulu tetap ke-flag cuma karena JUMLAHNYA 4,
        // sekarang TIDAK BOLEH, karena belum tentu benar-benar "menuju Berat" sebagai satu
        // kesatuan (1 parameter tinggi + beberapa nyaris ambang bukan sinyal kuat). Level akhir
        // tetap 'sedang' (keempatnya persis SEDANG_PARAMETERS, AND penuh terpenuhi).
        $this->addFullPanel([
            'Gula Darah Puasa' => '126', // ambang 120 -> margin 5%
            'Cholesterol' => '210',   // ambang 200 -> margin 5%
            'Trigliserida' => '155',  // ambang 150 -> margin 3.3%
            'LDL' => '135',           // ambang 130 -> margin 3.8%
        ]);

        $result = $this->service->classify($this->patient->fresh());

        $this->assertSame('sedang', $result->level);
        $this->assertFalse($result->early_detection_flag);
        $this->assertNull($result->early_detection_reason);
    }

    public function test_early_detection_flag_false_kalau_cuma_1_parameter_kombo_yang_tinggi(): void
    {
        // "Bukan hanya 1 parameter" -- Cholesterol jauh di atas ambang (margin 200%) TAPI
        // sendirian, parameter kombo lain normal -- TIDAK cukup untuk combo_breadth (butuh
        // minimal combo_min_parameters=3 parameter exceeded BERSAMAAN). Creatinine 1.8
        // ditambahkan supaya level akhir tetap 'sedang' (lewat direct-classifier, independen
        // dari kombinasi yang cuma 1 parameter) -- tanpa ini, cuma Cholesterol sendirian sejak
        // revisi AND ketat tidak menghasilkan klasifikasi apa pun (lihat tes lain di atas),
        // sehingga premise "level sedang tapi flag false" tidak akan pernah tercapai.
        $this->addFullPanel(['Cholesterol' => '600', 'Creatinine' => '1.8']); // ambang 200 -> margin 200%, sendirian

        $result = $this->service->classify($this->patient->fresh());

        $this->assertSame('sedang', $result->level);
        $this->assertFalse($result->early_detection_flag);
    }

    public function test_early_detection_flag_false_kalau_3_parameter_exceeded_tapi_bukan_semua_parameter_wajib(): void
    {
        // Jumlah sudah memenuhi combo_min_parameters (3, yaitu GDP+Cholesterol+Urea), TAPI
        // parameter yang exceeded bukan superset dari combo_required_parameters (Gula Darah
        // Puasa, LDL, Trigliserida) -- LDL & Trigliserida sama sekali tidak exceeded di sini,
        // jadi TIDAK BOLEH memicu combo_breadth meski margin tinggi dan jumlahnya cukup secara
        // hitungan generik. Creatinine 1.8 ditambahkan supaya level akhir tetap 'sedang' lewat
        // direct-classifier (kombinasi kombo di sini TIDAK memenuhi AND penuh Sedang karena
        // Trigliserida/LDL normal, jadi tanpa Creatinine hasilnya null, bukan 'sedang').
        $this->addFullPanel([
            'Gula Darah Puasa' => '192', // margin 60%, mandatory tapi cuma 1 dari 3
            'Cholesterol' => '320', // margin 60%
            'Urea' => '64',         // margin 60%
            'Creatinine' => '1.8',  // direct-classifier -> sedang, independen dari kombo di atas
        ]);

        $result = $this->service->classify($this->patient->fresh());

        $this->assertSame('sedang', $result->level);
        $this->assertFalse($result->early_detection_flag);
    }

    public function test_early_detection_flag_combo_breadth_saat_hanya_3_parameter_wajib_exceeded(): void
    {
        // Kasus minimum yang masih valid: HANYA Gula Darah Puasa, LDL, Trigliserida (persis
        // combo_required_parameters) yang exceeded, Cholesterol & Urea tetap normal -- tetap
        // harus memicu combo_breadth karena ketiganya sudah cukup (tidak butuh parameter
        // tambahan apa pun di luar yang wajib). Creatinine 1.8 ditambahkan supaya level akhir
        // tetap 'sedang' (Cholesterol normal di sini artinya AND penuh Sedang kombinasi GAGAL
        // sejak revisi AND ketat -- direct-classifier Creatinine yang membawa level ke 'sedang',
        // combo_breadth early-detection tetap dihitung dari kriteria yang sama seperti biasa).
        $this->addFullPanel([
            'Gula Darah Puasa' => '192', // margin 60%
            'Trigliserida' => '240',     // margin 60%
            'LDL' => '208',              // margin 60%
            'Creatinine' => '1.8',       // direct-classifier -> sedang
        ]);

        $result = $this->service->classify($this->patient->fresh());

        $this->assertSame('sedang', $result->level);
        $this->assertTrue($result->early_detection_flag);
        $comboReasons = collect($result->early_detection_reason)->where('type', 'combo_breadth');
        $this->assertCount(1, $comboReasons);
        $this->assertSame(['Cholesterol', 'Urea'], $comboReasons->first()['missing_parameters']);
    }

    public function test_early_detection_flag_true_dari_kombinasi_creatinine_hampir_berat_walau_kombo_hanya_sedang_rata_rata(): void
    {
        // Kasus GABUNGAN yang diminta secara eksplisit: indikator kombo cuma "sedang" biasa
        // (margin rendah, TIDAK memicu combo_breadth sendiri, dan malah TIDAK memenuhi AND
        // penuh Sedang -- cuma Cholesterol sendirian) TAPI Creatinine hampir menyentuh 2 --
        // tetap harus ke-flag sebagai pasien beresiko menuju Berat, lewat sinyal proximity
        // Creatinine SENDIRIAN (OR, bukan AND, dengan sinyal kombo), dan level akhir tetap
        // 'sedang' dari direct-classifier Creatinine (bukan dari kombo, yang sekarang gagal
        // AND penuhnya).
        $this->addFullPanel([
            'Cholesterol' => '210', // margin rendah (5%), tidak memicu combo_breadth
            'Creatinine' => '1.89', // proximity 63.3%, di atas ambang 60%
        ]);

        $result = $this->service->classify($this->patient->fresh());

        $this->assertSame('sedang', $result->level);
        $this->assertTrue($result->early_detection_flag);
        $reasons = collect($result->early_detection_reason);
        $this->assertCount(1, $reasons->where('type', 'proximity'));
        $this->assertCount(0, $reasons->where('type', 'combo_breadth'));
    }

    public function test_early_detection_flag_worsening_trend_lewat_3_klasifikasi_berturut_turut(): void
    {
        // Cholesterol naik terus 3 kali berturut-turut (210 -> 220 -> 230), parameter lain
        // tetap normal supaya sinyal proximity/combo_breadth tidak ikut memicu di klasifikasi
        // ke-3. Creatinine 1.8 (direct-classifier -> sedang) dipertahankan KONSTAN di seluruh 3
        // tahap supaya level akhir tetap 'sedang' setiap panggilan -- tanpa ini, Cholesterol
        // sendirian (sejak revisi AND ketat) tidak menghasilkan klasifikasi apa pun sama
        // sekali, dan riwayat 3-klasifikasi-berturut yang dibutuhkan detectWorseningTrend()
        // tidak akan pernah terbentuk.
        $this->addFullPanel(['Cholesterol' => '210', 'Creatinine' => '1.8']);
        $this->service->classify($this->patient->fresh());

        LabResultCache::where('patient_id', $this->patient->external_patient_id)
            ->where('parameter', 'Cholesterol')
            ->update(['value' => '220']);
        $this->service->classify($this->patient->fresh());

        LabResultCache::where('patient_id', $this->patient->external_patient_id)
            ->where('parameter', 'Cholesterol')
            ->update(['value' => '230']);
        $third = $this->service->classify($this->patient->fresh());

        $this->assertSame('sedang', $third->level);
        $this->assertTrue($third->early_detection_flag);
        $trendReasons = collect($third->early_detection_reason)->where('type', 'worsening_trend');
        $this->assertCount(1, $trendReasons);
        $this->assertSame('Cholesterol', $trendReasons->first()['parameter']);
        $this->assertSame([230, 220, 210], $trendReasons->first()['values_terbaru_ke_lama']);
    }

    public function test_early_detection_flag_selalu_false_untuk_level_berat(): void
    {
        // evaluateEarlyDetection() cuma jalan untuk level 'sedang' -- Creatinine 2.0 tepat di
        // ambang Berat (bukan 'mendekati', tapi SUDAH Berat) tidak boleh di-flag early detection.
        $this->addLabResult(500001, 'Creatinine', '2.0', '2026-07-20');

        $result = $this->service->classify($this->patient->fresh());

        $this->assertSame('berat', $result->level);
        $this->assertFalse($result->early_detection_flag);
        $this->assertNull($result->early_detection_reason);
    }
}
