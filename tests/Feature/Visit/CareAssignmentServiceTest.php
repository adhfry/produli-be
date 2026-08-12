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
use App\Services\Visit\CareAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Regresi untuk CareAssignmentService (revisi Bu Kadis) -- rencana kunjungan berulang, lihat
 * docblock migration create_care_assignments_table untuk konsep dasarnya.
 */
class CareAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private CareAssignmentService $service;

    private Puskesmas $puskesmasA;

    private Puskesmas $puskesmasB;

    private Kader $kaderA;

    private TenagaKesehatan $tkA;

    private User $assignedBy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CareAssignmentService::class);

        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $this->puskesmasA = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-A', 'nama' => 'Puskesmas A']);
        $this->puskesmasB = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-B', 'nama' => 'Puskesmas B']);

        $kaderUser = User::factory()->create();
        $this->kaderA = Kader::create(['user_id' => $kaderUser->id, 'puskesmas_id' => $this->puskesmasA->id, 'status_aktif' => true]);

        $tkUser = User::factory()->create();
        $this->tkA = TenagaKesehatan::create(['user_id' => $tkUser->id, 'puskesmas_id' => $this->puskesmasA->id, 'status_aktif' => true]);

        $this->assignedBy = User::factory()->create();
    }

    private function makePatient(array $overrides = []): PatientsCache
    {
        static $externalId = 0;
        $externalId++;

        return PatientsCache::create(array_merge([
            'external_patient_id' => 910000 + $externalId,
            'nik_hash' => 'HASH-'.$externalId,
            'nama' => 'Pasien '.$externalId,
            'wilayah_status' => 'resolved',
            'puskesmas_id' => $this->puskesmasA->id,
        ], $overrides));
    }

    public function test_ensure_kader_plan_membuat_plan_baru(): void
    {
        $patient = $this->makePatient();
        $assignment = VisitAssignment::create([
            'patient_id' => $patient->id,
            'kader_id' => $this->kaderA->id,
            'assigned_by' => $this->assignedBy->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'pending',
            'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);

        $plan = $this->service->ensureKaderPlan($assignment);

        $this->assertNotNull($plan);
        $this->assertSame('kader', $plan->worker_type);
        $this->assertSame($this->kaderA->id, $plan->kader_id);
        $this->assertSame('active', $plan->status);
        $this->assertSame(1, CareAssignment::count());
    }

    public function test_ensure_kader_plan_idempotent_tidak_duplikat(): void
    {
        $patient = $this->makePatient();
        $makeAssignment = fn () => VisitAssignment::create([
            'patient_id' => $patient->id,
            'kader_id' => $this->kaderA->id,
            'assigned_by' => $this->assignedBy->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'pending',
            'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);

        $first = $this->service->ensureKaderPlan($makeAssignment());
        $second = $this->service->ensureKaderPlan($makeAssignment());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CareAssignment::count());
    }

    public function test_assign_tenaga_kesehatan_membuat_plan_dan_kunjungan_pertama(): void
    {
        $patient = $this->makePatient();

        $plan = $this->service->assignTenagaKesehatan($patient, $this->tkA, $this->assignedBy, now()->toDateString());

        $this->assertSame('tenaga_kesehatan', $plan->worker_type);
        $this->assertSame($this->tkA->id, $plan->tenaga_kesehatan_id);
        $this->assertNotNull($plan->last_triggered_at);
        $this->assertSame(1, VisitAssignment::where('care_assignment_id', $plan->id)->count());

        $visit = VisitAssignment::where('care_assignment_id', $plan->id)->first();
        $this->assertSame($this->tkA->id, $visit->tenaga_kesehatan_id);
        $this->assertNull($visit->kader_id);
        $this->assertSame('cadence_generated', $visit->visit_origin);
    }

    public function test_assign_tenaga_kesehatan_ditolak_kalau_tidak_aktif(): void
    {
        $this->tkA->update(['status_aktif' => false]);
        $patient = $this->makePatient();

        $this->expectException(ValidationException::class);

        $this->service->assignTenagaKesehatan($patient, $this->tkA, $this->assignedBy, now()->toDateString());
    }

    public function test_assign_tenaga_kesehatan_ditolak_beda_puskesmas(): void
    {
        $patient = $this->makePatient(['puskesmas_id' => $this->puskesmasB->id]);

        $this->expectException(ValidationException::class);

        $this->service->assignTenagaKesehatan($patient, $this->tkA, $this->assignedBy, now()->toDateString());
    }

    public function test_assign_tenaga_kesehatan_ditolak_kalau_sudah_ada_plan_aktif(): void
    {
        $patient = $this->makePatient();
        $this->service->assignTenagaKesehatan($patient, $this->tkA, $this->assignedBy, now()->toDateString());

        $this->expectException(ValidationException::class);

        $this->service->assignTenagaKesehatan($patient, $this->tkA, $this->assignedBy, now()->toDateString());
    }

    public function test_generate_due_visit_membuat_kunjungan_baru_dan_update_last_triggered(): void
    {
        $patient = $this->makePatient();
        $plan = $this->service->assignTenagaKesehatan($patient, $this->tkA, $this->assignedBy, now()->subDays(30)->toDateString());
        // Selesaikan kunjungan pertama supaya generateDueVisit() bikin kunjungan BARU (bukan
        // numpuk di atas yang masih pending).
        VisitAssignment::where('care_assignment_id', $plan->id)->update(['status' => 'completed']);

        $visit = $this->service->generateDueVisit($plan->fresh());

        $this->assertSame('cadence_generated', $visit->visit_origin);
        $this->assertSame(2, VisitAssignment::where('care_assignment_id', $plan->id)->count());
        $this->assertTrue($plan->fresh()->last_triggered_at->isToday());
    }

    public function test_create_adhoc_visit_reset_last_triggered_at_dan_notifikasi_terkirim(): void
    {
        $patient = $this->makePatient();
        $plan = $this->service->assignTenagaKesehatan($patient, $this->tkA, $this->assignedBy, now()->subDays(10)->toDateString());

        $visit = $this->service->createAdhocVisit($plan->fresh(), $this->assignedBy, now()->toDateString());

        $this->assertSame('adhoc', $visit->visit_origin);
        $this->assertTrue($plan->fresh()->last_triggered_at->isToday());
        $this->assertSame(1, $this->tkA->user->notifications()->count());
    }

    public function test_create_adhoc_visit_ditolak_untuk_plan_kader(): void
    {
        $patient = $this->makePatient();
        $assignment = VisitAssignment::create([
            'patient_id' => $patient->id,
            'kader_id' => $this->kaderA->id,
            'assigned_by' => $this->assignedBy->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'pending',
            'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);
        $plan = $this->service->ensureKaderPlan($assignment);

        $this->expectException(ValidationException::class);

        $this->service->createAdhocVisit($plan, $this->assignedBy, now()->toDateString());
    }
}
