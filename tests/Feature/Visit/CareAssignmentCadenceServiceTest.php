<?php

namespace Tests\Feature\Visit;

use App\Models\CareAssignment;
use App\Models\Kabupaten;
use App\Models\Kader;
use App\Models\PatientsCache;
use App\Models\Puskesmas;
use App\Models\RiskClassification;
use App\Models\TenagaKesehatan;
use App\Models\User;
use App\Models\VisitAssignment;
use App\Services\Visit\CareAssignmentCadenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Regresi untuk CareAssignmentCadenceService (revisi Bu Kadis) -- kader tetap 7 hari,
 * tenaga_kesehatan 30 hari NORMAL / 14 hari kalau level risiko pasien SAAT INI = berat (dibaca
 * ulang tiap scan, bukan nilai tetap tersimpan -- lihat docblock service).
 */
class CareAssignmentCadenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private CareAssignmentCadenceService $service;

    private Puskesmas $puskesmas;

    private Kader $kader;

    private TenagaKesehatan $tk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CareAssignmentCadenceService::class);

        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $this->puskesmas = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-A', 'nama' => 'Puskesmas A']);

        $kaderUser = User::factory()->create();
        $this->kader = Kader::create(['user_id' => $kaderUser->id, 'puskesmas_id' => $this->puskesmas->id, 'status_aktif' => true]);

        $tkUser = User::factory()->create();
        $this->tk = TenagaKesehatan::create(['user_id' => $tkUser->id, 'puskesmas_id' => $this->puskesmas->id, 'status_aktif' => true]);
    }

    private function makePatient(array $overrides = []): PatientsCache
    {
        static $externalId = 0;
        $externalId++;

        return PatientsCache::create(array_merge([
            'external_patient_id' => 920000 + $externalId,
            'nik_hash' => 'HASH-'.$externalId,
            'nama' => 'Pasien '.$externalId,
            'wilayah_status' => 'resolved',
            'puskesmas_id' => $this->puskesmas->id,
        ], $overrides));
    }

    private function makeKaderPlan(PatientsCache $patient, ?string $lastTriggeredAt): CareAssignment
    {
        return CareAssignment::create([
            'patient_id' => $patient->id,
            'worker_type' => 'kader',
            'kader_id' => $this->kader->id,
            'puskesmas_id_snapshot' => $this->puskesmas->id,
            'status' => 'active',
            'last_triggered_at' => $lastTriggeredAt,
        ]);
    }

    private function makeTkPlan(PatientsCache $patient, ?string $lastTriggeredAt): CareAssignment
    {
        return CareAssignment::create([
            'patient_id' => $patient->id,
            'worker_type' => 'tenaga_kesehatan',
            'tenaga_kesehatan_id' => $this->tk->id,
            'puskesmas_id_snapshot' => $this->puskesmas->id,
            'status' => 'active',
            'last_triggered_at' => $lastTriggeredAt,
        ]);
    }

    public function test_plan_belum_pernah_trigger_selalu_due(): void
    {
        $plan = $this->makeKaderPlan($this->makePatient(), null);

        $count = $this->service->generateDueVisits();

        $this->assertSame(1, $count);
        $this->assertNotNull($plan->fresh()->last_triggered_at);
    }

    public function test_kader_due_setelah_7_hari(): void
    {
        $this->makeKaderPlan($this->makePatient(), now()->subDays(7)->toDateString());

        $this->assertSame(1, $this->service->generateDueVisits());
    }

    public function test_kader_belum_due_sebelum_7_hari(): void
    {
        $this->makeKaderPlan($this->makePatient(), now()->subDays(3)->toDateString());

        $this->assertSame(0, $this->service->generateDueVisits());
    }

    public function test_tenaga_kesehatan_due_setelah_30_hari_kalau_bukan_berat(): void
    {
        $patient = $this->makePatient();
        RiskClassification::create([
            'patient_id' => $patient->id,
            'level' => 'sedang',
            'criteria_snapshot' => [],
            'computed_at' => now(),
            'is_latest' => true,
        ]);
        $this->makeTkPlan($patient, now()->subDays(29)->toDateString());

        $this->assertSame(0, $this->service->generateDueVisits(), 'Belum due di hari ke-29 (butuh 30)');

        CareAssignment::query()->update(['last_triggered_at' => now()->subDays(30)->toDateString()]);
        $this->assertSame(1, $this->service->generateDueVisits());
    }

    public function test_tenaga_kesehatan_due_setelah_14_hari_kalau_pasien_berat(): void
    {
        $patient = $this->makePatient();
        RiskClassification::create([
            'patient_id' => $patient->id,
            'level' => 'berat',
            'criteria_snapshot' => [],
            'computed_at' => now(),
            'is_latest' => true,
        ]);
        $this->makeTkPlan($patient, now()->subDays(14)->toDateString());

        // 14 hari cukup untuk pasien Berat (bukan 30 seperti pasien lain).
        $this->assertSame(1, $this->service->generateDueVisits());
    }

    public function test_tidak_generate_kalau_masih_ada_kunjungan_terbuka(): void
    {
        $patient = $this->makePatient();
        $plan = $this->makeKaderPlan($patient, now()->subDays(10)->toDateString());
        VisitAssignment::create([
            'patient_id' => $patient->id,
            'kader_id' => $this->kader->id,
            'care_assignment_id' => $plan->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'pending',
            'priority' => 'ringan',
            'visit_origin' => 'cadence_generated',
            'puskesmas_id_snapshot' => $this->puskesmas->id,
        ]);

        $this->assertSame(0, $this->service->generateDueVisits());
    }

    public function test_plan_tidak_aktif_dilewati(): void
    {
        $plan = $this->makeKaderPlan($this->makePatient(), now()->subDays(30)->toDateString());
        $plan->update(['status' => 'ended']);

        $this->assertSame(0, $this->service->generateDueVisits());
    }

    // ---- upcomingDates()/isBlockedByOpenVisit() (permintaan user, fitur jadwal cadence) ----

    public function test_upcoming_dates_kader_berjarak_7_hari(): void
    {
        $plan = $this->makeKaderPlan($this->makePatient(), '2026-08-01');

        $dates = $this->service->upcomingDates($plan, 3);

        $this->assertSame(['2026-08-08', '2026-08-15', '2026-08-22'], array_map(fn ($d) => $d->toDateString(), $dates));
    }

    public function test_upcoming_dates_tanpa_last_triggered_dihitung_dari_hari_ini(): void
    {
        $plan = $this->makeKaderPlan($this->makePatient(), null);

        $dates = $this->service->upcomingDates($plan, 1);

        $this->assertSame(now()->addDays(7)->toDateString(), $dates[0]->toDateString());
    }

    public function test_upcoming_dates_tenaga_kesehatan_ikut_prioritas_pasien_terkini(): void
    {
        $patient = $this->makePatient();
        RiskClassification::create(['patient_id' => $patient->id, 'level' => 'berat', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);
        $plan = $this->makeTkPlan($patient, '2026-08-01');

        // Berat -> cadence 14 hari (tenaga_kesehatan_days_berat), bukan 30 hari normal.
        $dates = $this->service->upcomingDates($plan, 1);

        $this->assertSame('2026-08-15', $dates[0]->toDateString());
    }

    public function test_is_blocked_by_open_visit_true_kalau_ada_kunjungan_pending(): void
    {
        $patient = $this->makePatient();
        $plan = $this->makeKaderPlan($patient, '2026-08-01');
        VisitAssignment::create([
            'patient_id' => $patient->id,
            'kader_id' => $this->kader->id,
            'care_assignment_id' => $plan->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'pending',
            'priority' => 'ringan',
            'visit_origin' => 'cadence_generated',
            'puskesmas_id_snapshot' => $this->puskesmas->id,
        ]);

        $this->assertTrue($this->service->isBlockedByOpenVisit($plan));
    }

    public function test_is_blocked_by_open_visit_false_kalau_tidak_ada_kunjungan_terbuka(): void
    {
        $plan = $this->makeKaderPlan($this->makePatient(), '2026-08-01');

        $this->assertFalse($this->service->isBlockedByOpenVisit($plan));
    }

    // ---- rescheduleTo() (permintaan user, fitur "atur ulang jadwal") ----

    public function test_reschedule_to_menggeser_last_triggered_at_supaya_upcoming_dates_pertama_persis_next_date(): void
    {
        $plan = $this->makeKaderPlan($this->makePatient(), '2026-08-01');

        $this->service->rescheduleTo($plan, Carbon::parse('2026-09-01'));

        $dates = $this->service->upcomingDates($plan->fresh(), 1);
        $this->assertSame('2026-09-01', $dates[0]->toDateString());
    }

    public function test_reschedule_to_menolak_plan_yang_tidak_aktif(): void
    {
        $plan = $this->makeKaderPlan($this->makePatient(), '2026-08-01');
        $plan->update(['status' => 'ended']);

        $this->expectException(ValidationException::class);
        $this->service->rescheduleTo($plan, Carbon::parse('2026-09-01'));
    }

    public function test_reschedule_to_menolak_plan_yang_masih_diblokir_kunjungan_terbuka(): void
    {
        $patient = $this->makePatient();
        $plan = $this->makeKaderPlan($patient, '2026-08-01');
        VisitAssignment::create([
            'patient_id' => $patient->id,
            'kader_id' => $this->kader->id,
            'care_assignment_id' => $plan->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'pending',
            'priority' => 'ringan',
            'visit_origin' => 'cadence_generated',
            'puskesmas_id_snapshot' => $this->puskesmas->id,
        ]);

        $this->expectException(ValidationException::class);
        $this->service->rescheduleTo($plan, Carbon::parse('2026-09-01'));
    }
}
