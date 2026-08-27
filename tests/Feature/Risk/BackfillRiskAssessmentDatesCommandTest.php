<?php

namespace Tests\Feature\Risk;

use App\Models\LabResultCache;
use App\Models\PatientsCache;
use App\Models\RiskClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi untuk produli:backfill-risk-assessment-dates (temuan user, audit produksi: 9.466 dari
 * 10.406 baris risk_classifications assessment_date NULL, dipicu kejanggalan nyata pasien
 * A. JAZILI id 9976 -- 2 baris klasifikasi computed_at berdekatan 12 & 17 Agustus padahal
 * SAMA-SAMA dari data lab 8 Mei, bikin "Riwayat & Tren Kondisi" menampilkan tanggal job compute,
 * bukan tanggal lab asli). Lihat docblock command untuk rasional lengkap rekonstruksinya.
 */
class BackfillRiskAssessmentDatesCommandTest extends TestCase
{
    use RefreshDatabase;

    private PatientsCache $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->patient = PatientsCache::create([
            'external_patient_id' => 998001,
            'nik_hash' => 'HASH-998001',
            'nama' => 'Pasien Uji Backfill',
            'wilayah_status' => 'unknown',
        ]);
    }

    public function test_backfill_mengisi_assessment_date_dari_lab_yang_sudah_diketahui_sistem_saat_computed_at(): void
    {
        LabResultCache::create([
            'external_id' => 1,
            'patient_id' => $this->patient->external_patient_id,
            'parameter' => 'Cholesterol',
            'value' => '181',
            'tanggal_periksa' => '2026-05-08',
            'synced_at' => '2026-05-22 08:53:44',
        ]);

        $classification = RiskClassification::create([
            'patient_id' => $this->patient->id,
            'level' => 'sedang',
            'criteria_snapshot' => [],
            'computed_at' => '2026-08-12 10:14:49',
            'assessment_date' => null,
            'is_latest' => true,
        ]);

        $this->artisan('produli:backfill-risk-assessment-dates')
            ->expectsConfirmation('Lanjutkan mengisi 1 baris risk_classifications.assessment_date sekarang?', 'yes')
            ->assertExitCode(0);

        $this->assertSame('2026-05-08', $classification->fresh()->assessment_date->toDateString());
    }

    public function test_backfill_mengambil_tanggal_periksa_terbaru_dari_beberapa_parameter(): void
    {
        LabResultCache::create(['external_id' => 1, 'patient_id' => $this->patient->external_patient_id, 'parameter' => 'Cholesterol', 'value' => '181', 'tanggal_periksa' => '2026-05-08', 'synced_at' => '2026-05-22']);
        LabResultCache::create(['external_id' => 2, 'patient_id' => $this->patient->external_patient_id, 'parameter' => 'Gula Darah Puasa', 'value' => '130', 'tanggal_periksa' => '2026-06-15', 'synced_at' => '2026-06-20']);

        $classification = RiskClassification::create([
            'patient_id' => $this->patient->id,
            'level' => 'sedang',
            'criteria_snapshot' => [],
            'computed_at' => '2026-08-01 00:00:00',
            'assessment_date' => null,
            'is_latest' => true,
        ]);

        $this->artisan('produli:backfill-risk-assessment-dates')->expectsConfirmation(
            'Lanjutkan mengisi 1 baris risk_classifications.assessment_date sekarang?', 'yes'
        )->assertExitCode(0);

        $this->assertSame('2026-06-15', $classification->fresh()->assessment_date->toDateString());
    }

    public function test_backfill_mengabaikan_lab_yang_baru_diketahui_sistem_setelah_computed_at(): void
    {
        // synced_at SETELAH computed_at -- data ini belum diketahui sistem saat klasifikasi lama
        // itu dihitung, TIDAK BOLEH ikut jadi assessment_date (mengubah histori jadi seolah-olah
        // klasifikasi lama itu "tahu" data yang sebenarnya baru masuk belakangan).
        LabResultCache::create([
            'external_id' => 1,
            'patient_id' => $this->patient->external_patient_id,
            'parameter' => 'Cholesterol',
            'value' => '181',
            'tanggal_periksa' => '2026-08-20',
            'synced_at' => '2026-08-25',
        ]);

        $classification = RiskClassification::create([
            'patient_id' => $this->patient->id,
            'level' => 'tidak_berisiko',
            'criteria_snapshot' => [],
            'computed_at' => '2026-08-12 10:14:49',
            'assessment_date' => null,
            'is_latest' => true,
        ]);

        // wouldFillCount = 0 (satu-satunya lab yang ada belum diketahui sistem saat itu) --
        // command langsung selesai tanpa prompt confirm() sama sekali.
        $this->artisan('produli:backfill-risk-assessment-dates')->assertExitCode(0);

        $this->assertNull($classification->fresh()->assessment_date);
    }

    public function test_backfill_tidak_menimpa_assessment_date_yang_sudah_terisi(): void
    {
        LabResultCache::create([
            'external_id' => 1,
            'patient_id' => $this->patient->external_patient_id,
            'parameter' => 'Cholesterol',
            'value' => '181',
            'tanggal_periksa' => '2026-05-08',
            'synced_at' => '2026-05-22',
        ]);

        $classification = RiskClassification::create([
            'patient_id' => $this->patient->id,
            'level' => 'sedang',
            'criteria_snapshot' => [],
            'computed_at' => '2026-08-12 10:14:49',
            'assessment_date' => '2026-01-01',
            'is_latest' => true,
        ]);

        $this->artisan('produli:backfill-risk-assessment-dates')->assertExitCode(0);

        $this->assertSame('2026-01-01', $classification->fresh()->assessment_date->toDateString());
    }

    public function test_dry_run_tidak_menulis_apa_pun(): void
    {
        LabResultCache::create([
            'external_id' => 1,
            'patient_id' => $this->patient->external_patient_id,
            'parameter' => 'Cholesterol',
            'value' => '181',
            'tanggal_periksa' => '2026-05-08',
            'synced_at' => '2026-05-22',
        ]);

        $classification = RiskClassification::create([
            'patient_id' => $this->patient->id,
            'level' => 'sedang',
            'criteria_snapshot' => [],
            'computed_at' => '2026-08-12 10:14:49',
            'assessment_date' => null,
            'is_latest' => true,
        ]);

        $this->artisan('produli:backfill-risk-assessment-dates --dry-run')->assertExitCode(0);

        $this->assertNull($classification->fresh()->assessment_date);
    }

    public function test_dibatalkan_operator_tidak_menulis_apa_pun(): void
    {
        LabResultCache::create([
            'external_id' => 1,
            'patient_id' => $this->patient->external_patient_id,
            'parameter' => 'Cholesterol',
            'value' => '181',
            'tanggal_periksa' => '2026-05-08',
            'synced_at' => '2026-05-22',
        ]);

        $classification = RiskClassification::create([
            'patient_id' => $this->patient->id,
            'level' => 'sedang',
            'criteria_snapshot' => [],
            'computed_at' => '2026-08-12 10:14:49',
            'assessment_date' => null,
            'is_latest' => true,
        ]);

        $this->artisan('produli:backfill-risk-assessment-dates')->expectsConfirmation(
            'Lanjutkan mengisi 1 baris risk_classifications.assessment_date sekarang?', 'no'
        )->assertExitCode(0);

        $this->assertNull($classification->fresh()->assessment_date);
    }
}
