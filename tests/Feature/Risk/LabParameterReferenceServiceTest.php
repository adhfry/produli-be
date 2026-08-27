<?php

namespace Tests\Feature\Risk;

use App\Models\RiskThreshold;
use App\Services\Risk\LabParameterReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi untuk LabParameterReferenceService (permintaan user, fitur "Tren Hasil Pemeriksaan" --
 * grafik % terhadap rujukan). SENGAJA pakai risk_thresholds (ambang tunggal, sudah dikonfirmasi
 * user), BUKAN reference_ranges_cache gender/umur-spesifik yang sengaja nonaktif.
 */
class LabParameterReferenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private LabParameterReferenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(LabParameterReferenceService::class);

        RiskThreshold::insert([
            ['parameter' => 'Trigliserida', 'level' => 'sedang', 'operator' => '>', 'is_direct_classifier' => false, 'threshold_min' => 140, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'Creatinine', 'level' => 'sedang', 'operator' => 'between', 'is_direct_classifier' => true, 'threshold_min' => 1.70, 'threshold_max' => 1.90, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['parameter' => 'Creatinine', 'level' => 'berat', 'operator' => '>=', 'is_direct_classifier' => true, 'threshold_min' => 2.00, 'threshold_max' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            // Nonaktif -- HARUS diabaikan (bukan ikut jadi kandidat reference_boundary).
            ['parameter' => 'Trigliserida', 'level' => 'berat', 'operator' => '>', 'is_direct_classifier' => false, 'threshold_min' => 500, 'threshold_max' => null, 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_nilai_di_atas_ambang_tunggal_hitung_persen_dan_zona_waspada(): void
    {
        // Trigliserida di setUp cuma py tier 'sedang' (140) -- tidak ada tier 'berat' aktif,
        // jadi level tercocok maksimal 'sedang' -> zone 'waspada' (bukan 'tinggi', itu KHUSUS
        // level 'berat', lihat test creatinine di bawah utk kasus zone 'tinggi').
        $result = $this->service->evaluate('Trigliserida', 266);

        $this->assertSame(140.0, $result['reference_boundary']);
        $this->assertSame(190.0, $result['percent_of_reference']);
        $this->assertSame('waspada', $result['zone']);
    }

    public function test_nilai_di_bawah_ambang_zona_normal(): void
    {
        $result = $this->service->evaluate('Trigliserida', 100);

        $this->assertSame(140.0, $result['reference_boundary']);
        $this->assertEqualsWithDelta(71.4, $result['percent_of_reference'], 0.1);
        $this->assertSame('normal', $result['zone']);
    }

    public function test_creatinine_2_tier_nilai_di_tier_sedang_zona_waspada(): void
    {
        $result = $this->service->evaluate('Creatinine', 1.8);

        // reference_boundary = threshold_min TERKECIL antar tier (1.70, bukan 2.00).
        $this->assertSame(1.7, $result['reference_boundary']);
        $this->assertSame('waspada', $result['zone']);
    }

    public function test_creatinine_2_tier_nilai_di_tier_berat_zona_tinggi(): void
    {
        $result = $this->service->evaluate('Creatinine', 2.1);

        $this->assertSame(1.7, $result['reference_boundary']);
        $this->assertSame('tinggi', $result['zone']);
    }

    public function test_parameter_tanpa_ambang_terkonfigurasi_semua_null(): void
    {
        // HDL -- ADA data lab, TIDAK PERNAH punya risk_thresholds (dikonfirmasi audit produksi).
        $result = $this->service->evaluate('HDL', 68);

        $this->assertNull($result['reference_boundary']);
        $this->assertNull($result['percent_of_reference']);
        $this->assertNull($result['zone']);
    }

    public function test_ambang_nonaktif_tidak_ikut_dihitung(): void
    {
        // Trigliserida 600 -- kalau tier 'berat' (nonaktif, 500) ikut terhitung, boundary jadi
        // salah (500). Harus tetap pakai tier 'sedang' (140, aktif) sbg satu-satunya kandidat --
        // matched level 'sedang' (bukan 'berat', krn tier itu nonaktif) -> zone 'waspada'.
        $result = $this->service->evaluate('Trigliserida', 600);

        $this->assertSame(140.0, $result['reference_boundary']);
        $this->assertSame('waspada', $result['zone']);
    }
}
