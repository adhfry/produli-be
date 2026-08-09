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
 * - Berat: KEENAM parameter (Gula Darah Puasa, Creatinine, Cholesterol, Trigliserida, LDL,
 *   Urea) harus LENGKAP tersedia DAN semuanya melebihi nilai rujukan sekaligus.
 * - Sedang: salah satu dari Gula Darah Puasa/Cholesterol/Trigliserida/LDL melebihi rujukan,
 *   tapi tidak memenuhi kriteria Berat.
 * - Ringan: HANYA Gula Darah Puasa yang melebihi rujukan di antara subset Sedang itu.
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
            ['parameter' => 'Gula Darah Puasa', 'level' => 'sedang', 'operator' => '>', 'threshold_min' => 120, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'Gula Darah Puasa', 'level' => 'berat', 'operator' => '>', 'threshold_min' => 200, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'Creatinine', 'level' => 'sedang', 'operator' => '>', 'threshold_min' => 1.3, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'Cholesterol', 'level' => 'sedang', 'operator' => '>', 'threshold_min' => 200, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'Trigliserida', 'level' => 'sedang', 'operator' => '>', 'threshold_min' => 150, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'LDL', 'level' => 'sedang', 'operator' => '>', 'threshold_min' => 130, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'Urea', 'level' => 'sedang', 'operator' => '>', 'threshold_min' => 40, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
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

    public function test_semua_enam_parameter_tersedia_dan_melebihi_menghasilkan_berat(): void
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

        $this->assertSame('berat', $result->level);
    }

    public function test_satu_parameter_tersedia_tapi_normal_gagalkan_kriteria_berat_jatuh_ke_sedang(): void
    {
        // Gula Darah Puasa sengaja dibiarkan normal -- 5 parameter lain melebihi. Karena TIDAK
        // SEMUA yang tersedia melebihi, ini bukan Berat, tapi Cholesterol/Trigliserida/LDL
        // tetap melebihi -> Sedang (bukan Ringan, karena bukan "hanya Gula Darah Puasa").
        $this->addFullPanel([
            'Creatinine' => '2.0',
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
        $this->addFullPanel([
            'Gula Darah Puasa' => '250',
            'Creatinine' => '2.0',
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

    public function test_tidak_ada_threshold_yang_match_tidak_membuat_baris_baru(): void
    {
        $this->addLabResult(500001, 'Gula Darah Puasa', '250', '2026-07-20');
        $this->service->classify($this->patient->fresh());

        LabResultCache::where('external_id', 500001)->update(['value' => '80']);
        $result = $this->service->classify($this->patient->fresh());

        $this->assertNull($result);
        $this->assertSame(1, RiskClassification::where('patient_id', $this->patient->id)->count());
        $this->assertTrue(RiskClassification::where('patient_id', $this->patient->id)->first()->is_latest);
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
}
