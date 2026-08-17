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
 * Regresi untuk status NONAKTIF jalur presisi umur+gender (SilakesReferenceRangeService) --
 * keputusan eksplisit user: standar resmi PRODULI (landing page "Pemeriksaan & Nilai Rujukan")
 * memakai SATU ambang tunggal per parameter, tanpa tingkatan umur maupun gender. Lihat docblock
 * App\Services\Risk\SilakesReferenceRangeService (PARAMETER_MAP sengaja dikosongkan) dan
 * App\Services\Risk\RiskClassificationService::resolvePrecisionBand().
 *
 * Fokus test ini: data di reference_ranges_cache (kalau ada, mis. tersinkron dari SiLAKES untuk
 * keperluan lain) TIDAK PERNAH dikonsultasikan lagi oleh RiskClassificationService -- SEMUA 6
 * parameter (termasuk Creatinine, yang memang sudah dari awal tidak pernah dipetakan) SELALU
 * lewat RiskThreshold, apa pun kelengkapan data pasien (gender/tgl_lahir) atau isi cache-nya.
 */
class RiskClassificationPrecisionTest extends TestCase
{
    use RefreshDatabase;

    private RiskClassificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(RiskClassificationService::class);

        RiskThreshold::insert([
            ['parameter' => 'Gula Darah Puasa', 'level' => 'sedang', 'operator' => '>=', 'is_direct_classifier' => false, 'threshold_min' => 130, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'Creatinine', 'level' => 'sedang', 'operator' => 'between', 'is_direct_classifier' => true, 'threshold_min' => 1.7, 'threshold_max' => 1.9, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'Creatinine', 'level' => 'berat', 'operator' => '>=', 'is_direct_classifier' => true, 'threshold_min' => 2.0, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Band presisi GDP MASIH ditaruh di reference_ranges_cache di sini (seolah SiLAKES
        // sudah pernah sync) justru untuk MEMBUKTIKAN keberadaannya tidak lagi berpengaruh --
        // kalau jalur presisi diam-diam masih aktif, test di bawah yang mengecek TIDAK adanya
        // key 'source' akan gagal.
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

    public function test_gdp_selalu_lewat_risk_threshold_walau_pasien_lengkap_gender_dan_tgl_lahir_dan_cache_terisi(): void
    {
        // Data pasien SELENGKAP mungkin (gender+tgl_lahir terisi) DAN reference_ranges_cache
        // punya band gula_darah_puasa yang cocok -- kalau jalur presisi masih aktif, criteria
        // snapshot akan punya key 'source' => 'silakes_reference_ranges'. Harus TIDAK ada.
        $patient = $this->patient(['gender' => 'L', 'tgl_lahir' => '1980-01-01']);
        $this->addLabResult($patient, 700001, 'Gula Darah Puasa', '135');

        $result = $this->service->classify($patient->fresh());

        $this->assertNotNull($result);
        $this->assertSame('ringan', $result->level);

        $gdpCriteria = collect($result->criteria_snapshot)->firstWhere('parameter', 'Gula Darah Puasa');
        $this->assertNotNull($gdpCriteria);
        $this->assertArrayNotHasKey('source', $gdpCriteria);
        $this->assertSame('>=', $gdpCriteria['operator']);
        $this->assertEquals(130, $gdpCriteria['threshold_min']);
    }

    public function test_gdp_129_di_bawah_ambang_risk_threshold_tidak_exceeded(): void
    {
        $patient = $this->patient(['gender' => 'L', 'tgl_lahir' => '1980-01-01']);
        $this->addLabResult($patient, 700002, 'Gula Darah Puasa', '129');

        $result = $this->service->classify($patient->fresh());

        $this->assertNull($result);
    }

    public function test_kombinasi_sedang_pakai_risk_threshold_untuk_seluruh_parameter(): void
    {
        $patient = $this->patient(['gender' => 'P', 'tgl_lahir' => '1975-06-15']);

        RiskThreshold::insert([
            ['parameter' => 'Cholesterol', 'level' => 'sedang', 'operator' => '>', 'is_direct_classifier' => false, 'threshold_min' => 200, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'Trigliserida', 'level' => 'sedang', 'operator' => '>', 'is_direct_classifier' => false, 'threshold_min' => 150, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'LDL', 'level' => 'sedang', 'operator' => '>', 'is_direct_classifier' => false, 'threshold_min' => 130, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->addLabResult($patient, 700010, 'Gula Darah Puasa', '135');
        $this->addLabResult($patient, 700011, 'Cholesterol', '250');
        $this->addLabResult($patient, 700012, 'Trigliserida', '180');
        $this->addLabResult($patient, 700013, 'LDL', '160');

        $result = $this->service->classify($patient->fresh());

        $this->assertNotNull($result);
        $this->assertSame('sedang', $result->level);

        foreach (['Gula Darah Puasa', 'Cholesterol', 'Trigliserida', 'LDL'] as $parameter) {
            $criteria = collect($result->criteria_snapshot)->firstWhere('parameter', $parameter);
            $this->assertArrayNotHasKey('source', $criteria);
        }
    }

    public function test_creatinine_tetap_selalu_lewat_risk_threshold_direct_classifier(): void
    {
        // Creatinine sudah dari awal tidak pernah dipetakan -- tes ini sekadar memastikan
        // status itu tidak berubah setelah PARAMETER_MAP dikosongkan untuk parameter lain.
        $patient = $this->patient(['gender' => 'L', 'tgl_lahir' => '1980-01-01']);
        $this->addLabResult($patient, 700040, 'Creatinine', '1.8');

        $result = $this->service->classify($patient->fresh());

        $this->assertNotNull($result);
        $this->assertSame('sedang', $result->level);

        $criteria = collect($result->criteria_snapshot)->firstWhere('parameter', 'Creatinine');
        $this->assertArrayNotHasKey('source', $criteria);
        $this->assertTrue($criteria['is_direct_classifier']);
    }
}
