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
 * Regresi untuk RiskClassificationService (docs/planning/02 §3, kriteria REVISI —
 * lintas-parameter, bukan lagi level tertinggi per baris threshold yang match):
 * - Berat: KELIMA parameter kombinasi (Gula Darah Puasa, Cholesterol, Trigliserida, LDL,
 *   Urea) harus LENGKAP tersedia DAN semuanya melebihi nilai rujukan sekaligus.
 * - Sedang: salah satu dari Gula Darah Puasa/Cholesterol/Trigliserida/LDL melebihi rujukan,
 *   tapi tidak memenuhi kriteria Berat.
 * - Ringan: HANYA Gula Darah Puasa yang melebihi rujukan di antara subset Sedang itu.
 *
 * REVISI Bu Kadis (lihat juga docblock RiskClassificationService): Creatinine keluar dari
 * kombinasi 5-parameter di atas, jadi "direct classifier" bertingkat (1.7-1.9=Sedang,
 * >=2.0=Berat) yang bisa menentukan level SENDIRIAN. Level akhir = paling parah antara jalur
 * kombinasi dan jalur direct-classifier. Tier baru 'tidak_berisiko' ditulis saat pasien yang
 * PERNAH diklasifikasi membaik total (tidak ada lagi yang melebihi rujukan).
 *
 * Nama parameter di sini HARUS persis sama dengan kolom lab_results_cache.parameter dari
 * SiLAKES asli -- "GDP" (singkatan medis umum) TIDAK PERNAH muncul di data nyata, cuma
 * "Gula Darah Puasa". Salah satu bug produksi nyata yang pernah bikin patients_classified
 * selalu 0 meski ribuan hasil lab sudah tersinkron -- lihat riwayat percakapan.
 *
 * Termasuk regresi khusus untuk kekhawatiran "delta sync tidak menjamin urutan kronologis"
 * (docs/planning/02 §3) yang sempat ditanyakan user.
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

        $this->assertNull($result);
    }

    public function test_creatinine_berat_menang_walau_kombinasi_lain_cuma_sedang(): void
    {
        // Creatinine 2.1 (direct-classifier Berat) berdiri sendiri, parameter lain hanya
        // memicu Sedang lewat jalur kombinasi (GDP normal, jadi bukan Berat kombinasi) --
        // level akhir harus ambil yang TERPARAH (Berat), bukan Sedang dari jalur kombinasi.
        $this->addFullPanel([
            'Creatinine' => '2.1',
            'Cholesterol' => '300',
        ]);

        $result = $this->service->classify($this->patient->fresh());

        $this->assertSame('berat', $result->level);
    }

    public function test_satu_parameter_tersedia_tapi_normal_gagalkan_kriteria_berat_jatuh_ke_sedang(): void
    {
        // Gula Darah Puasa sengaja dibiarkan normal -- 4 dari 5 parameter kombinasi melebihi.
        // Karena TIDAK SEMUA yang tersedia melebihi, ini bukan Berat lewat jalur kombinasi,
        // tapi Cholesterol/Trigliserida/LDL tetap melebihi -> Sedang (bukan Ringan, karena
        // bukan "hanya Gula Darah Puasa"). Creatinine SENGAJA dibiarkan normal (bukan 2.0)
        // supaya tidak ikut memicu jalur direct-classifier Berat -- itu diuji terpisah.
        $this->addFullPanel([
            'Cholesterol' => '300',
            'Trigliserida' => '200',
            'LDL' => '180',
            'Urea' => '60',
        ]);

        $result = $this->service->classify($this->patient->fresh());

        $this->assertSame('sedang', $result->level);
    }

    public function test_hanya_gula_darah_puasa_tersedia_dan_melebihi_menghasilkan_ringan_bukan_berat(): void
    {
        // Kasus yang sengaja diklarifikasi: parameter lain belum PERNAH diperiksa (bukan
        // "tersedia tapi normal") -- kriteria Berat butuh KELENGKAPAN keenam parameter,
        // jadi Gula Darah Puasa sendirian yang tinggi tidak boleh otomatis jadi Berat.
        $this->addLabResult(500001, 'Gula Darah Puasa', '250', '2026-07-20');

        $result = $this->service->classify($this->patient->fresh());

        $this->assertSame('ringan', $result->level);
    }

    public function test_gula_darah_puasa_dan_kolesterol_melebihi_tanpa_parameter_lain_menghasilkan_sedang(): void
    {
        $this->addLabResult(500001, 'Gula Darah Puasa', '250', '2026-07-20');
        $this->addLabResult(500002, 'Cholesterol', '300', '2026-07-20');

        $result = $this->service->classify($this->patient->fresh());

        $this->assertSame('sedang', $result->level);
    }

    public function test_gula_darah_puasa_melebihi_dan_nilai_non_numerik_di_skip_dengan_log(): void
    {
        Log::spy();

        $this->addLabResult(500001, 'Gula Darah Puasa', '250', '2026-07-20');
        $this->addLabResult(500002, 'Cholesterol', 'ABNORMAL', '2026-07-20');

        $result = $this->service->classify($this->patient->fresh());

        $this->assertNotNull($result);
        $this->assertSame('ringan', $result->level); // Cholesterol di-skip -> cuma GDP yang tersedia
        $this->assertCount(2, $result->criteria_snapshot); // match baris threshold sedang DAN berat
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

        // Gula Darah Puasa turun ke normal -> kriteria Berat gagal (tidak semua yang tersedia
        // melebihi lagi), tapi Cholesterol/Trigliserida/LDL masih melebihi -> turun ke Sedang.
        LabResultCache::where('patient_id', $this->patient->external_patient_id)
            ->where('parameter', 'Gula Darah Puasa')
            ->update(['value' => '90']);
        $second = $this->service->classify($this->patient->fresh());

        $this->assertSame('berat', $first->level);
        $this->assertSame('sedang', $second->level);
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
        $this->addLabResult(500001, 'Gula Darah Puasa', '250', '2026-07-20');
        $first = $this->service->classify($this->patient->fresh());

        $second = $this->service->classify($this->patient->fresh());

        $this->assertSame('ringan', $first->level);
        $this->assertNull($second, 'Panggilan kedua tanpa perubahan data harus no-op (null), bukan baris baru.');
        $this->assertSame(1, RiskClassification::where('patient_id', $this->patient->id)->count());
    }

    public function test_classify_ulang_dengan_nilai_berubah_tetap_menulis_baris_baru(): void
    {
        // Kebalikan dari test di atas -- guard idempotensi TIDAK BOLEH menghalangi perubahan
        // kondisi pasien yang sungguhan.
        $this->addLabResult(500001, 'Gula Darah Puasa', '250', '2026-07-20');
        $this->service->classify($this->patient->fresh());

        LabResultCache::where('external_id', 500001)->update(['value' => '260']);
        $second = $this->service->classify($this->patient->fresh());

        $this->assertNotNull($second);
        $this->assertSame(2, RiskClassification::where('patient_id', $this->patient->id)->count());
    }

    public function test_belum_pernah_diklasifikasi_dan_tidak_ada_yang_match_tetap_null(): void
    {
        // Pasien belum PERNAH punya baris risk_classifications sama sekali -- kalau tidak ada
        // parameter yang melebihi rujukan, tetap tidak menulis apa-apa (bukan tidak_berisiko),
        // supaya tidak bikin noise untuk pasien yang memang belum pernah relevan.
        $this->addLabResult(500001, 'Gula Darah Puasa', '80', '2026-07-20');

        $result = $this->service->classify($this->patient->fresh());

        $this->assertNull($result);
        $this->assertSame(0, RiskClassification::where('patient_id', $this->patient->id)->count());
    }

    public function test_membaik_dari_pernah_diklasifikasi_menjadi_tidak_ada_yang_match_menulis_tidak_berisiko(): void
    {
        // Pasien PERNAH divonis Ringan (GDP 250), lalu nilainya kembali normal sepenuhnya --
        // revisi Bu Kadis: ini harus ditulis sebagai baris baru 'tidak_berisiko' (pasien
        // membaik total), bukan diam-diam tetap 'ringan' selamanya di is_latest.
        $this->addLabResult(500001, 'Gula Darah Puasa', '250', '2026-07-20');
        $first = $this->service->classify($this->patient->fresh());

        LabResultCache::where('external_id', 500001)->update(['value' => '80']);
        $second = $this->service->classify($this->patient->fresh());

        $this->assertSame('ringan', $first->level);
        $this->assertNotNull($second);
        $this->assertSame('tidak_berisiko', $second->level);
        $this->assertSame(2, RiskClassification::where('patient_id', $this->patient->id)->count());
        $this->assertFalse($first->fresh()->is_latest);
        $this->assertTrue($second->fresh()->is_latest);
    }

    public function test_klasifikasi_pakai_tanggal_periksa_terbaru_bukan_urutan_insert_atau_synced_at(): void
    {
        // synced_at sengaja DIBALIK dari tanggal_periksa: hasil lama (tanggal jauh, nilai
        // rendah) baru "disinkron" HARI INI, hasil baru (tanggal dekat, nilai tinggi) justru
        // sudah lama tersinkron — meniru delta sync yang tidak berurutan kronologis
        // (docs/planning/02 §3). Kalau kode salah urut pakai synced_at/insert order, hasilnya
        // akan salah pilih 90 (tidak match threshold apa pun) alih-alih 250 (ringan, sendirian).
        $this->addLabResult(500002, 'Gula Darah Puasa', '90', '2026-01-10', now()->toDateTimeString());
        $this->addLabResult(500001, 'Gula Darah Puasa', '250', '2026-07-20', '2026-01-01 00:00:00');

        $result = $this->service->classify($this->patient->fresh());

        $this->assertNotNull($result, 'Harusnya tetap match dari tanggal_periksa 2026-07-20, bukan NULL');
        $this->assertSame('ringan', $result->level);
    }

    public function test_tiebreak_synced_at_saat_tanggal_periksa_sama_persis(): void
    {
        $this->addLabResult(500001, 'Gula Darah Puasa', '90', '2026-07-20', '2026-07-20 08:00:00');
        $this->addLabResult(500002, 'Gula Darah Puasa', '250', '2026-07-20', '2026-07-20 09:00:00');

        $result = $this->service->classify($this->patient->fresh());

        // synced_at lebih baru (retest di hari yang sama) yang menang -> 250 -> ringan (sendirian).
        $this->assertSame('ringan', $result->level);
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
        // kesatuan (1 parameter tinggi + beberapa nyaris ambang bukan sinyal kuat).
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
        // minimal combo_min_parameters=3 parameter exceeded BERSAMAAN).
        $this->addFullPanel(['Cholesterol' => '600']); // ambang 200 -> margin 200%, sendirian

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
        // hitungan generik.
        $this->addFullPanel([
            'Gula Darah Puasa' => '192', // margin 60%, mandatory tapi cuma 1 dari 3
            'Cholesterol' => '320', // margin 60%
            'Urea' => '64',         // margin 60%
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
        // tambahan apa pun di luar yang wajib).
        $this->addFullPanel([
            'Gula Darah Puasa' => '192', // margin 60%
            'Trigliserida' => '240',     // margin 60%
            'LDL' => '208',              // margin 60%
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
        // (margin rendah, TIDAK memicu combo_breadth sendiri) TAPI Creatinine hampir menyentuh
        // 2 -- tetap harus ke-flag sebagai pasien beresiko menuju Berat, lewat sinyal proximity
        // Creatinine SENDIRIAN (OR, bukan AND, dengan sinyal kombo).
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
        // tetap normal supaya sinyal proximity/combo_breadth tidak ikut memicu di klasifikasi ke-3.
        $this->addFullPanel(['Cholesterol' => '210']);
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

    public function test_early_detection_flag_selalu_false_untuk_level_ringan(): void
    {
        $this->addLabResult(500001, 'Gula Darah Puasa', '250', '2026-07-20');

        $result = $this->service->classify($this->patient->fresh());

        $this->assertSame('ringan', $result->level);
        $this->assertFalse($result->early_detection_flag);
        $this->assertNull($result->early_detection_reason);
    }
}
