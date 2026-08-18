<?php

namespace Tests\Feature\Risk;

use App\Models\Kabupaten;
use App\Models\Kader;
use App\Models\PatientsCache;
use App\Models\Puskesmas;
use App\Models\RiskClassification;
use App\Models\RiskTransitionScore;
use App\Models\User;
use App\Models\VisitAssignment;
use App\Models\VisitReport;
use App\Services\Performance\RiskTransitionScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi untuk RiskTransitionScorer -- dasar algoritma scoring kinerja puskesmas (Top 5).
 * Lihat juga Tests\Feature\Dashboard\DashboardControllerTest untuk regresi agregasi/scoping di
 * level dashboard (di sini murni fokus ke unit scoring itu sendiri).
 */
class RiskTransitionScorerTest extends TestCase
{
    use RefreshDatabase;

    private RiskTransitionScorer $scorer;

    private PatientsCache $patient;

    private Puskesmas $puskesmas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = app(RiskTransitionScorer::class);

        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $this->puskesmas = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-X', 'nama' => 'Puskesmas X']);

        $this->patient = PatientsCache::create([
            'external_patient_id' => 5001,
            'nik_hash' => 'HASH-5001',
            'nama' => 'Pasien Skor',
            'puskesmas_id' => $this->puskesmas->id,
            'wilayah_status' => 'unknown',
        ]);
    }

    private function classification(string $level, $at): RiskClassification
    {
        return RiskClassification::create([
            'patient_id' => $this->patient->id,
            'level' => $level,
            'criteria_snapshot' => [],
            'computed_at' => $at,
            'is_latest' => false,
        ]);
    }

    private function validatedVisit($createdAt, string $validationStatus = 'valid'): VisitReport
    {
        $kaderUser = User::factory()->create(['puskesmas_id' => $this->puskesmas->id]);
        $kader = Kader::create(['user_id' => $kaderUser->id, 'puskesmas_id' => $this->puskesmas->id, 'status_aktif' => true]);

        $assignment = VisitAssignment::create([
            'patient_id' => $this->patient->id,
            'kader_id' => $kader->id,
            'scheduled_date' => $createdAt->toDateString(),
            'status' => 'completed',
            'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmas->id,
        ]);

        $report = VisitReport::create([
            'assignment_id' => $assignment->id,
            'gps_lat' => -7.0,
            'gps_lng' => 113.0,
            'photo_path' => 'test-photo.jpg',
            'kondisi' => 'Baik',
            'validation_status' => $validationStatus,
        ]);
        $report->forceFill(['created_at' => $createdAt])->save();

        return $report;
    }

    /**
     * Matriks poin transisi LENGKAP (16 kombinasi) -- spesifikasi scoring kinerja puskesmas.
     * Terkendali->Terkendali sengaja +2 (bukan 0), semua kombinasi lain murni (previous_numeric
     * - current_numeric) * 10 dengan skala Berat=3/Sedang=2/Ringan=1/Terkendali=0.
     */
    public function test_matriks_poin_transisi_16_kombinasi(): void
    {
        $cases = [
            ['berat', 'berat', 0],
            ['berat', 'sedang', 10],
            ['berat', 'ringan', 20],
            ['berat', 'tidak_berisiko', 30],
            ['sedang', 'berat', -10],
            ['sedang', 'sedang', 0],
            ['sedang', 'ringan', 10],
            ['sedang', 'tidak_berisiko', 20],
            ['ringan', 'berat', -20],
            ['ringan', 'sedang', -10],
            ['ringan', 'ringan', 0],
            ['ringan', 'tidak_berisiko', 10],
            ['tidak_berisiko', 'berat', -30],
            ['tidak_berisiko', 'sedang', -20],
            ['tidak_berisiko', 'ringan', -10],
            ['tidak_berisiko', 'tidak_berisiko', 2],
        ];

        foreach ($cases as [$previous, $current, $expected]) {
            $this->assertSame(
                $expected,
                $this->scorer->point($previous, $current),
                "{$previous} -> {$current} harus menghasilkan {$expected} poin"
            );
        }
    }

    public function test_assessment_pertama_pasien_tidak_menghasilkan_baris_skor(): void
    {
        $current = $this->classification('sedang', now());

        $result = $this->scorer->score($this->patient, null, $current);

        $this->assertNull($result);
        $this->assertSame(0, RiskTransitionScore::count());
    }

    public function test_transisi_dengan_kunjungan_tervalidasi_di_antara_assessment_eligible(): void
    {
        $previous = $this->classification('berat', now()->subDays(10));
        $this->validatedVisit(now()->subDays(5));
        $current = $this->classification('sedang', now()->subDays(2));

        $score = $this->scorer->score($this->patient, $previous, $current);

        $this->assertNotNull($score);
        $this->assertTrue($score->eligible);
        $this->assertSame(10, $score->base_point);
        $this->assertSame(10, $score->final_point);
        $this->assertSame(1, $score->risk_delta);
        $this->assertNotNull($score->related_validated_visit_id);
        $this->assertSame($this->puskesmas->id, $score->puskesmas_id);
    }

    public function test_transisi_tanpa_kunjungan_sama_sekali_tetap_tercatat_tapi_tidak_eligible(): void
    {
        $previous = $this->classification('berat', now()->subDays(10));
        $current = $this->classification('sedang', now()->subDays(2));

        $score = $this->scorer->score($this->patient, $previous, $current);

        $this->assertNotNull($score);
        $this->assertFalse($score->eligible);
        $this->assertNull($score->related_validated_visit_id);
        // Poin TETAP dihitung apa adanya (audit trail lengkap) walau tidak eligible --
        // PuskesmasPerformanceScoringService yang memfilter eligible=true, bukan scorer ini.
        $this->assertSame(10, $score->base_point);
    }

    public function test_kunjungan_pending_tidak_dihitung_sebagai_bukti_intervensi(): void
    {
        $previous = $this->classification('berat', now()->subDays(10));
        $this->validatedVisit(now()->subDays(5), 'pending');
        $current = $this->classification('sedang', now()->subDays(2));

        $score = $this->scorer->score($this->patient, $previous, $current);

        $this->assertFalse($score->eligible);
    }

    public function test_kunjungan_invalid_tidak_dihitung_sebagai_bukti_intervensi(): void
    {
        $previous = $this->classification('berat', now()->subDays(10));
        $this->validatedVisit(now()->subDays(5), 'invalid');
        $current = $this->classification('sedang', now()->subDays(2));

        $score = $this->scorer->score($this->patient, $previous, $current);

        $this->assertFalse($score->eligible);
    }

    public function test_kunjungan_tervalidasi_di_luar_jendela_waktu_tidak_dihitung(): void
    {
        $previous = $this->classification('berat', now()->subDays(10));
        // Kunjungan tervalidasi ADA, tapi terjadi SEBELUM assessment 'berat' -- bukan bukti
        // intervensi UNTUK transisi berat->sedang ini.
        $this->validatedVisit(now()->subDays(20));
        $current = $this->classification('sedang', now()->subDays(2));

        $score = $this->scorer->score($this->patient, $previous, $current);

        $this->assertFalse($score->eligible);
    }

    public function test_idempotent_score_dipanggil_dua_kali_untuk_current_yang_sama(): void
    {
        $previous = $this->classification('berat', now()->subDays(10));
        $this->validatedVisit(now()->subDays(5));
        $current = $this->classification('sedang', now()->subDays(2));

        $first = $this->scorer->score($this->patient, $previous, $current);
        $second = $this->scorer->score($this->patient, $previous, $current);

        $this->assertSame(1, RiskTransitionScore::count());
        $this->assertSame($first->id, $second->id);
    }

    public function test_transisi_bertahap_menghasilkan_baris_terpisah_bukan_satu_lompatan(): void
    {
        // Berat -> Sedang -> Ringan -> Terkendali via 3 assessment TERPISAH harus menghasilkan
        // 3 baris (+10, +10, +10 = total +30) -- BUKAN 1 baris tunggal Berat->Terkendali (+30).
        $berat = $this->classification('berat', now()->subDays(30));
        $this->validatedVisit(now()->subDays(25));
        $sedang = $this->classification('sedang', now()->subDays(20));
        $this->scorer->score($this->patient, $berat, $sedang);

        $this->validatedVisit(now()->subDays(15));
        $ringan = $this->classification('ringan', now()->subDays(10));
        $this->scorer->score($this->patient, $sedang, $ringan);

        $this->validatedVisit(now()->subDays(5));
        $terkendali = $this->classification('tidak_berisiko', now()->subDays(1));
        $this->scorer->score($this->patient, $ringan, $terkendali);

        $this->assertSame(3, RiskTransitionScore::count());
        $this->assertSame(30, (int) RiskTransitionScore::sum('final_point'));
        $this->assertTrue(RiskTransitionScore::where('eligible', true)->count() === 3);
    }
}
