<?php

namespace Tests\Feature\CareAssignment;

use App\Models\CareAssignment;
use App\Models\Kabupaten;
use App\Models\Kader;
use App\Models\PatientsCache;
use App\Models\Puskesmas;
use App\Models\TenagaKesehatan;
use App\Models\User;
use App\Models\VisitAssignment;
use App\Models\VisitAssignmentCompanion;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresi untuk POST /care-assignments (revisi Bu Kadis PMO) -- kunjungan hari-1 bersama
 * kader+tenaga_kesehatan lewat parameter kader_id opsional, lihat docblock
 * CareAssignmentService::assignTenagaKesehatan().
 */
class CareAssignmentControllerTest extends TestCase
{
    use RefreshDatabase;

    private Puskesmas $puskesmas;

    private TenagaKesehatan $tenagaKesehatan;

    private Kader $kader;

    private PatientsCache $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $this->puskesmas = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-A', 'nama' => 'Puskesmas A']);

        $tkUser = User::factory()->create();
        $this->tenagaKesehatan = TenagaKesehatan::create(['user_id' => $tkUser->id, 'puskesmas_id' => $this->puskesmas->id, 'status_aktif' => true]);

        $kaderUser = User::factory()->create();
        $this->kader = Kader::create(['user_id' => $kaderUser->id, 'puskesmas_id' => $this->puskesmas->id, 'status_aktif' => true]);

        $this->patient = PatientsCache::create([
            'external_patient_id' => 920001,
            'nik_hash' => 'HASH-920001',
            'nama' => 'Pasien Uji',
            'wilayah_status' => 'resolved',
            'puskesmas_id' => $this->puskesmas->id,
        ]);
    }

    public function test_pj_prolanis_assign_tenaga_kesehatan_dengan_kader_menandai_companion(): void
    {
        $pj = User::factory()->create(['puskesmas_id' => $this->puskesmas->id]);
        $pj->assignRole('pj_prolanis');
        Sanctum::actingAs($pj);

        $response = $this->postJson('/api/v1/care-assignments', [
            'patient_id' => $this->patient->id,
            'tenaga_kesehatan_id' => $this->tenagaKesehatan->id,
            'scheduled_date' => now()->toDateString(),
            'kader_id' => $this->kader->id,
        ]);

        $response->assertCreated();

        $plan = CareAssignment::where('patient_id', $this->patient->id)->where('worker_type', 'tenaga_kesehatan')->firstOrFail();
        $visit = VisitAssignment::where('care_assignment_id', $plan->id)->firstOrFail();
        $this->assertSame(1, VisitAssignmentCompanion::where('assignment_id', $visit->id)->where('kader_id', $this->kader->id)->count());
        $this->assertSame(1, CareAssignment::where('worker_type', 'kader')->where('kader_id', $this->kader->id)->where('status', 'active')->count());
    }

    public function test_assign_tenaga_kesehatan_tanpa_kader_id_tetap_berhasil_seperti_sebelumnya(): void
    {
        $pj = User::factory()->create(['puskesmas_id' => $this->puskesmas->id]);
        $pj->assignRole('pj_prolanis');
        Sanctum::actingAs($pj);

        $response = $this->postJson('/api/v1/care-assignments', [
            'patient_id' => $this->patient->id,
            'tenaga_kesehatan_id' => $this->tenagaKesehatan->id,
            'scheduled_date' => now()->toDateString(),
        ]);

        $response->assertCreated();
        $this->assertSame(0, VisitAssignmentCompanion::count());
        $this->assertSame(0, CareAssignment::where('worker_type', 'kader')->count());
    }

    public function test_kader_ditolak_menugaskan_tenaga_kesehatan(): void
    {
        Sanctum::actingAs($this->kader->user);

        $response = $this->postJson('/api/v1/care-assignments', [
            'patient_id' => $this->patient->id,
            'tenaga_kesehatan_id' => $this->tenagaKesehatan->id,
            'scheduled_date' => now()->toDateString(),
        ]);

        $response->assertStatus(403);
    }

    public function test_tanpa_login_ditolak_401(): void
    {
        $this->postJson('/api/v1/care-assignments', [
            'patient_id' => $this->patient->id,
            'tenaga_kesehatan_id' => $this->tenagaKesehatan->id,
            'scheduled_date' => now()->toDateString(),
        ])->assertStatus(401);
    }

    // ---- PATCH /care-assignments/{id}/reschedule (permintaan user, fitur "atur ulang jadwal") ----

    private function makeActiveKaderPlan(): CareAssignment
    {
        return CareAssignment::create([
            'patient_id' => $this->patient->id,
            'worker_type' => 'kader',
            'kader_id' => $this->kader->id,
            'puskesmas_id_snapshot' => $this->puskesmas->id,
            'status' => 'active',
            'last_triggered_at' => now()->subDays(3)->toDateString(),
        ]);
    }

    public function test_pj_prolanis_reschedule_plan_aktif_menggeser_upcoming_dates(): void
    {
        $plan = $this->makeActiveKaderPlan();
        $pj = User::factory()->create(['puskesmas_id' => $this->puskesmas->id]);
        $pj->assignRole('pj_prolanis');
        Sanctum::actingAs($pj);

        $nextDate = now()->addDays(10)->toDateString();
        $response = $this->patchJson("/api/v1/care-assignments/{$plan->id}/reschedule", [
            'next_date' => $nextDate,
        ]);

        $response->assertOk();
        $this->assertSame($nextDate, $response->json('data.upcoming_dates.0'));
    }

    public function test_reschedule_ditolak_422_kalau_masih_ada_kunjungan_terbuka(): void
    {
        $plan = $this->makeActiveKaderPlan();
        VisitAssignment::create([
            'patient_id' => $this->patient->id,
            'kader_id' => $this->kader->id,
            'care_assignment_id' => $plan->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'pending',
            'priority' => 'ringan',
            'visit_origin' => 'cadence_generated',
            'puskesmas_id_snapshot' => $this->puskesmas->id,
        ]);
        $pj = User::factory()->create(['puskesmas_id' => $this->puskesmas->id]);
        $pj->assignRole('pj_prolanis');
        Sanctum::actingAs($pj);

        $response = $this->patchJson("/api/v1/care-assignments/{$plan->id}/reschedule", [
            'next_date' => now()->addDays(10)->toDateString(),
        ]);

        $response->assertStatus(422);
    }

    public function test_reschedule_ditolak_403_kalau_beda_puskesmas(): void
    {
        $plan = $this->makeActiveKaderPlan();
        $kabupatenLain = Kabupaten::create(['kode_kemendagri' => '35.30', 'nama' => 'Sumenep Lain']);
        $puskesmasLain = Puskesmas::create(['kabupaten_id' => $kabupatenLain->id, 'kode_internal' => 'PKM-B', 'nama' => 'Puskesmas B']);
        $pj = User::factory()->create(['puskesmas_id' => $puskesmasLain->id]);
        $pj->assignRole('pj_prolanis');
        Sanctum::actingAs($pj);

        $response = $this->patchJson("/api/v1/care-assignments/{$plan->id}/reschedule", [
            'next_date' => now()->addDays(10)->toDateString(),
        ]);

        $response->assertStatus(403);
    }
}
