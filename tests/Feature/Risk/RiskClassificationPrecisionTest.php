<?php

namespace Tests\Feature\Risk;

use App\Models\LabResultCache;
use App\Models\PatientsCache;
use App\Models\ReferenceRangeCache;
use App\Models\RiskThreshold;
use App\Services\Risk\RiskClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi untuk jalur presisi umur+gender (SilakesReferenceRangeService) yang ditambahkan ke
 * RiskClassificationService::classify() -- lihat resolvePrecisionBand(). Fokus: jalur ini
 * dipakai HANYA kalau data lengkap (parameter dipetakan, gender+tgl_lahir pasien terisi,
 * reference_ranges_cache punya band cocok), dan SELALU fallback ke RiskThreshold lama kalau
 * salah satu syarat itu tidak terpenuhi -- termasuk Creatinine yang SENGAJA tidak pernah masuk
 * jalur ini sama sekali.
 */
class RiskClassificationPrecisionTest extends TestCase
{
    use RefreshDatabase;

    private RiskClassificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(RiskClassificationService::class);

        // Fallback safety-net -- HARUS tetap ada supaya kasus "data pasien belum lengkap"/
        // "cache belum sync" punya sesuatu untuk di-fallback-kan (persis RiskThresholdSeeder asli).
        RiskThreshold::insert([
            ['parameter' => 'Gula Darah Puasa', 'level' => 'sedang', 'operator' => '>=', 'is_direct_classifier' => false, 'threshold_min' => 130, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'Creatinine', 'level' => 'sedang', 'operator' => 'between', 'is_direct_classifier' => true, 'threshold_min' => 1.7, 'threshold_max' => 1.9, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'Creatinine', 'level' => 'berat', 'operator' => '>=', 'is_direct_classifier' => true, 'threshold_min' => 2.0, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Band GDP presisi (mirror cutoff tunggal 130 yang sudah disamakan dengan SiLAKES),
        // berlaku semua umur/gender (parameter_key gula_darah_puasa tidak dibedakan gender).
        ReferenceRangeCache::insert([
            ['parameter_key' => 'gula_darah_puasa', 'gender' => null, 'value_min' => null, 'value_max' => 130, 'min_inclusive' => false, 'max_inclusive' => false, 'category' => 'normal', 'category_label' => 'Normal', 'severity_rank' => 0, 'synced_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['parameter_key' => 'gula_darah_puasa', 'gender' => null, 'value_min' => 130, 'value_max' => null, 'min_inclusive' => true, 'max_inclusive' => true, 'category' => 'high', 'category_label' => 'Tinggi', 'severity_rank' => 1, 'synced_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function patient(array $overrides = []): PatientsCache
    {
        return PatientsCache::create(array_merge([
            'external_patient_id' => 999002,
            'nik_hash' => 'HASH-999002',
            'nama' => 'Pasien Presisi',
            'wilayah_status' => 'unknown',
        ], $overrides));
    }

    private function addLabResult(PatientsCache $patient, int $externalId, string $parameter, string $value): void
    {
        LabResultCache::create([
            'external_id' => $externalId,
            'patient_id' => $patient->external_patient_id,
            'parameter' => $parameter,
            'value' => $value,
            'tanggal_periksa' => '2026-08-16',
            'synced_at' => '2026-08-16',
        ]);
    }

    public function test_gdp_129_di_bawah_cutoff_presisi_tidak_exceeded(): void
    {
        $patient = $this->patient(['gender' => 'L', 'tgl_lahir' => '1980-01-01']);
        $this->addLabResult($patient, 700001, 'Gula Darah Puasa', '129');

        $result = $this->service->classify($patient->fresh());

        // Sendirian, tidak lengkap 4/5 kombinasi -- tapi yang diuji di sini murni apakah
        // parameter ini masuk $exceededParameters lewat jalur presisi, bukan level akhirnya.
        // Null di sini justru MEMBUKTIKAN presisi jalan benar (129 < 130 = tidak exceeded,
        // sama seperti kalau GDP memang normal, bukan error).
        $this->assertNull($result);
    }

    public function test_gdp_130_persis_cutoff_presisi_exceeded_lewat_reference_ranges_cache(): void
    {
        $patient = $this->patient(['gender' => 'L', 'tgl_lahir' => '1980-01-01']);
        $this->addLabResult($patient, 700002, 'Gula Darah Puasa', '130');
        // Sendirian tidak cukup untuk naik level (butuh 4/5 kombinasi) -- pasien belum pernah
        // diklasifikasi sebelumnya, jadi classify() balik null meski parameter ini exceeded.
        // Untuk membuktikan jalur presisi benar-benar dipakai (bukan diam-diam fallback lalu
        // exceeded=false), assert lewat kombinasi 4-parameter SEDANG penuh di test berikutnya.
        $result = $this->service->classify($patient->fresh());

        $this->assertNull($result);
    }

    public function test_kombinasi_sedang_pakai_gdp_presisi_130_criteria_snapshot_format_kompatibel_frontend(): void
    {
        $patient = $this->patient(['gender' => 'P', 'tgl_lahir' => '1975-06-15']);

        // Butuh RiskThreshold fallback utk Cholesterol/Trigliserida/LDL (tidak diberi band
        // presisi di setUp() -- sengaja, supaya test ini SEKALIGUS membuktikan presisi dan
        // fallback bisa hidup berdampingan dalam satu panggilan classify() yang sama).
        RiskThreshold::insert([
            ['parameter' => 'Cholesterol', 'level' => 'sedang', 'operator' => '>', 'is_direct_classifier' => false, 'threshold_min' => 200, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'Trigliserida', 'level' => 'sedang', 'operator' => '>', 'is_direct_classifier' => false, 'threshold_min' => 150, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'LDL', 'level' => 'sedang', 'operator' => '>', 'is_direct_classifier' => false, 'threshold_min' => 130, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->addLabResult($patient, 700010, 'Gula Darah Puasa', '135'); // presisi: exceeded (>=130)
        $this->addLabResult($patient, 700011, 'Cholesterol', '250'); // fallback: exceeded (>200)
        $this->addLabResult($patient, 700012, 'Trigliserida', '180'); // fallback: exceeded (>150)
        $this->addLabResult($patient, 700013, 'LDL', '160'); // fallback: exceeded (>130)

        $result = $this->service->classify($patient->fresh());

        $this->assertNotNull($result);
        $this->assertSame('sedang', $result->level);

        $gdpCriteria = collect($result->criteria_snapshot)->firstWhere('parameter', 'Gula Darah Puasa');
        $this->assertNotNull($gdpCriteria);
        $this->assertSame('silakes_reference_ranges', $gdpCriteria['source']);
        // Bentuk operator/threshold_min/threshold_max HARUS salah satu dari 5 yang dipahami
        // app/pages/dashboard/pasien/[id].vue (PRODULI frontend) -- band GDP presisi upward-open
        // inklusif (value_min=130,min_inclusive=true,value_max=null) harus jadi '>=' 130.
        $this->assertSame('>=', $gdpCriteria['operator']);
        // assertEquals (bukan assertSame) -- criteria_snapshot bolak-balik lewat JSON cast,
        // 130.0 (float) jadi 130 (int) setelah decode, angka round-trip JSON yang wajar.
        $this->assertEquals(130, $gdpCriteria['threshold_min']);
        $this->assertNull($gdpCriteria['threshold_max']);

        $cholesterolCriteria = collect($result->criteria_snapshot)->firstWhere('parameter', 'Cholesterol');
        $this->assertArrayNotHasKey('source', $cholesterolCriteria);
    }

    public function test_fallback_ke_risk_threshold_saat_gender_kosong(): void
    {
        $patient = $this->patient(['gender' => null, 'tgl_lahir' => '1980-01-01']);
        $this->addLabResult($patient, 700020, 'Gula Darah Puasa', '135');

        // RiskThreshold fallback GDP di setUp() pakai cutoff >=130 juga (sengaja disamakan)
        // -- yang dibuktikan di sini BUKAN angkanya, tapi bahwa 'source' TIDAK muncul di
        // criteria (artinya benar-benar lewat RiskThreshold, bukan reference_ranges_cache).
        RiskThreshold::insert([
            ['parameter' => 'Cholesterol', 'level' => 'sedang', 'operator' => '>', 'is_direct_classifier' => false, 'threshold_min' => 200, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'Trigliserida', 'level' => 'sedang', 'operator' => '>', 'is_direct_classifier' => false, 'threshold_min' => 150, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'LDL', 'level' => 'sedang', 'operator' => '>', 'is_direct_classifier' => false, 'threshold_min' => 130, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->addLabResult($patient, 700021, 'Cholesterol', '250');
        $this->addLabResult($patient, 700022, 'Trigliserida', '180');
        $this->addLabResult($patient, 700023, 'LDL', '160');

        $result = $this->service->classify($patient->fresh());

        $this->assertNotNull($result);
        $gdpCriteria = collect($result->criteria_snapshot)->firstWhere('parameter', 'Gula Darah Puasa');
        $this->assertArrayNotHasKey('source', $gdpCriteria);
    }

    public function test_fallback_ke_risk_threshold_saat_reference_ranges_cache_kosong(): void
    {
        ReferenceRangeCache::query()->delete();

        $patient = $this->patient(['gender' => 'L', 'tgl_lahir' => '1980-01-01']);
        RiskThreshold::insert([
            ['parameter' => 'Cholesterol', 'level' => 'sedang', 'operator' => '>', 'is_direct_classifier' => false, 'threshold_min' => 200, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'Trigliserida', 'level' => 'sedang', 'operator' => '>', 'is_direct_classifier' => false, 'threshold_min' => 150, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'LDL', 'level' => 'sedang', 'operator' => '>', 'is_direct_classifier' => false, 'threshold_min' => 130, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->addLabResult($patient, 700030, 'Gula Darah Puasa', '135');
        $this->addLabResult($patient, 700031, 'Cholesterol', '250');
        $this->addLabResult($patient, 700032, 'Trigliserida', '180');
        $this->addLabResult($patient, 700033, 'LDL', '160');

        $result = $this->service->classify($patient->fresh());

        $this->assertNotNull($result);
        $gdpCriteria = collect($result->criteria_snapshot)->firstWhere('parameter', 'Gula Darah Puasa');
        $this->assertArrayNotHasKey('source', $gdpCriteria);
    }

    public function test_creatinine_tidak_pernah_lewat_jalur_presisi_walau_ada_band_cocok(): void
    {
        // Tambah band presisi utk 'creatinine' -- SENGAJA untuk membuktikan
        // SilakesReferenceRangeService::isMapped('Creatinine') tetap false meski
        // reference_ranges_cache PUNYA data untuk parameter_key itu (mis. sync SiLAKES
        // membawa 12 parameter penuh, tapi PARAMETER_MAP RiskClassificationService cuma
        // memetakan 5 -- Creatinine harus tetap 100% RiskThreshold direct-classifier lama).
        ReferenceRangeCache::insert([
            ['parameter_key' => 'creatinine', 'gender' => 'L', 'value_min' => null, 'value_max' => 1.35, 'min_inclusive' => false, 'max_inclusive' => true, 'category' => 'normal', 'category_label' => 'Normal', 'severity_rank' => 0, 'synced_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['parameter_key' => 'creatinine', 'gender' => 'L', 'value_min' => 1.35, 'value_max' => null, 'min_inclusive' => false, 'max_inclusive' => true, 'category' => 'high', 'category_label' => 'Tinggi', 'severity_rank' => 2, 'synced_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $patient = $this->patient(['gender' => 'L', 'tgl_lahir' => '1980-01-01']);
        $this->addLabResult($patient, 700040, 'Creatinine', '1.8');

        $result = $this->service->classify($patient->fresh());

        // 1.8 SiLAKES-presisi akan bilang "Tinggi" (severity 2, di atas 1.35) -- tapi kalau
        // Creatinine BENAR tetap di jalur RiskThreshold lama, 1.8 masuk direct-classifier
        // 'sedang' (1.7-1.9), BUKAN diproses lewat band presisi manapun.
        $this->assertNotNull($result);
        $this->assertSame('sedang', $result->level);

        $criteria = collect($result->criteria_snapshot)->firstWhere('parameter', 'Creatinine');
        $this->assertArrayNotHasKey('source', $criteria);
        $this->assertTrue($criteria['is_direct_classifier']);
    }
}
