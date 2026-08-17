<?php

namespace Tests\Feature\Visit;

use App\Mail\VisitAssignedMail;
use App\Models\Desa;
use App\Models\Kabupaten;
use App\Models\Kader;
use App\Models\Kecamatan;
use App\Models\PatientsCache;
use App\Models\Puskesmas;
use App\Models\RiskClassification;
use App\Models\TenagaKesehatan;
use App\Models\User;
use App\Models\VisitAssignment;
use App\Models\VisitAssignmentCompanion;
use App\Models\VisitReport;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresi untuk POST/GET /api/v1/visit-assignments (docs/planning/02 §7) -- PJ/admin_puskesmas
 * membuat assignment lewat VisitAssignmentService yang sudah ada, list ter-scope role.
 */
class VisitAssignmentControllerTest extends TestCase
{
    use RefreshDatabase;

    private Puskesmas $puskesmasA;

    private Puskesmas $puskesmasB;

    private Kader $kaderA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $this->puskesmasA = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-A', 'nama' => 'Puskesmas A']);
        $this->puskesmasB = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-B', 'nama' => 'Puskesmas B']);

        $kaderUserA = User::factory()->create(['puskesmas_id' => $this->puskesmasA->id]);
        $kaderUserA->assignRole('kader');
        $this->kaderA = Kader::create(['user_id' => $kaderUserA->id, 'puskesmas_id' => $this->puskesmasA->id, 'status_aktif' => true]);
    }

    private function makeUser(string $role, ?Puskesmas $puskesmas = null): User
    {
        $user = User::factory()->create(['puskesmas_id' => $puskesmas?->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makePatient(Puskesmas $puskesmas, int $externalId, array $overrides = []): PatientsCache
    {
        return PatientsCache::create(array_merge([
            'external_patient_id' => $externalId,
            'nik_hash' => 'HASH-'.$externalId,
            'nama' => 'Pasien '.$externalId,
            'puskesmas_id' => $puskesmas->id,
            'wilayah_status' => 'resolved',
        ], $overrides));
    }

    // ---- Create ----

    public function test_pj_prolanis_membuat_assignment(): void
    {
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $patient = $this->makePatient($this->puskesmasA, 1);

        Sanctum::actingAs($pj);

        $response = $this->postJson('/api/v1/visit-assignments', [
            'patient_id' => $patient->id,
            'kader_id' => $this->kaderA->id,
            'scheduled_date' => now()->addDay()->toDateString(),
            'priority' => 'sedang',
        ]);

        $response->assertCreated();
        $this->assertSame(1, VisitAssignment::where('patient_id', $patient->id)->count());
        $this->assertSame('pending', $response->json('data.status'));
    }

    public function test_pj_ditolak_assign_pasien_beda_puskesmas(): void
    {
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $patientB = $this->makePatient($this->puskesmasB, 2);
        $kaderB = $this->makeKader($this->puskesmasB);

        Sanctum::actingAs($pj);

        $response = $this->postJson('/api/v1/visit-assignments', [
            'patient_id' => $patientB->id,
            'kader_id' => $kaderB->id,
            'scheduled_date' => now()->addDay()->toDateString(),
            'priority' => 'sedang',
        ]);

        $response->assertStatus(403);
    }

    public function test_kader_tidak_bisa_membuat_assignment(): void
    {
        $patient = $this->makePatient($this->puskesmasA, 1);

        Sanctum::actingAs($this->kaderA->user);

        $response = $this->postJson('/api/v1/visit-assignments', [
            'patient_id' => $patient->id,
            'kader_id' => $this->kaderA->id,
            'scheduled_date' => now()->addDay()->toDateString(),
            'priority' => 'sedang',
        ]);

        $response->assertStatus(403);
    }

    public function test_assignment_ditolak_wilayah_belum_resolved(): void
    {
        // VisitAssignmentService yang sudah ada tetap menegakkan aturan wilayah_status-nya
        // sendiri lewat jalur HTTP ini -- bukan cuma dari tinker/tes service langsung.
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $patient = $this->makePatient($this->puskesmasA, 1, ['wilayah_status' => 'unknown']);

        Sanctum::actingAs($pj);

        $response = $this->postJson('/api/v1/visit-assignments', [
            'patient_id' => $patient->id,
            'kader_id' => $this->kaderA->id,
            'scheduled_date' => now()->addDay()->toDateString(),
            'priority' => 'sedang',
        ]);

        $response->assertStatus(422);
    }

    public function test_assignment_via_phone_contact_untuk_pasien_berat_tanpa_wilayah_resolved(): void
    {
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $patient = $this->makePatient($this->puskesmasA, 1, [
            'wilayah_status' => 'unknown',
            'puskesmas_id' => null,
            'phone' => '081234567890',
        ]);
        RiskClassification::create([
            'patient_id' => $patient->id, 'level' => 'berat',
            'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true,
        ]);

        Sanctum::actingAs($pj);

        $response = $this->postJson('/api/v1/visit-assignments', [
            'patient_id' => $patient->id,
            'kader_id' => $this->kaderA->id,
            'scheduled_date' => now()->addDay()->toDateString(),
            'priority' => 'berat',
        ]);

        $response->assertCreated();
        $this->assertSame('phone_contact', $response->json('data.assignment_method'));
        $this->assertSame('081234567890', $response->json('data.patient.phone'));
    }

    // ---- Bulk create ----

    public function test_bulk_assign_berhasil_untuk_semua_pasien_valid(): void
    {
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $patient1 = $this->makePatient($this->puskesmasA, 1);
        $patient2 = $this->makePatient($this->puskesmasA, 2);

        Sanctum::actingAs($pj);

        $response = $this->postJson('/api/v1/visit-assignments/bulk', [
            'kader_id' => $this->kaderA->id,
            'patient_ids' => [$patient1->id, $patient2->id],
            'scheduled_date' => now()->addDay()->toDateString(),
            'priority' => 'berat',
        ]);

        $response->assertCreated();
        $this->assertCount(2, $response->json('data.created'));
        $this->assertCount(0, $response->json('data.failed'));
        $this->assertSame(2, VisitAssignment::where('kader_id', $this->kaderA->id)->count());
    }

    public function test_bulk_assign_partial_success_melaporkan_yang_gagal(): void
    {
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $patientValid = $this->makePatient($this->puskesmasA, 1);
        $patientWilayahBelumResolved = $this->makePatient($this->puskesmasA, 2, ['wilayah_status' => 'unknown']);

        Sanctum::actingAs($pj);

        $response = $this->postJson('/api/v1/visit-assignments/bulk', [
            'kader_id' => $this->kaderA->id,
            'patient_ids' => [$patientValid->id, $patientWilayahBelumResolved->id],
            'scheduled_date' => now()->addDay()->toDateString(),
            'priority' => 'berat',
        ]);

        // Sebagian lolos -> tetap 201 (bukan all-or-nothing), yang gagal dilaporkan alasannya.
        $response->assertCreated();
        $this->assertCount(1, $response->json('data.created'));
        $this->assertCount(1, $response->json('data.failed'));
        $this->assertSame($patientWilayahBelumResolved->id, $response->json('data.failed.0.patient_id'));
        $this->assertSame(1, VisitAssignment::where('patient_id', $patientValid->id)->count());
        $this->assertSame(0, VisitAssignment::where('patient_id', $patientWilayahBelumResolved->id)->count());
    }

    public function test_bulk_assign_pasien_tidak_ditemukan_dilaporkan_gagal(): void
    {
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);

        Sanctum::actingAs($pj);

        $response = $this->postJson('/api/v1/visit-assignments/bulk', [
            'kader_id' => $this->kaderA->id,
            'patient_ids' => [999999],
            'scheduled_date' => now()->addDay()->toDateString(),
            'priority' => 'berat',
        ]);

        // Semua gagal -> tidak ada yang dibuat, 200 (bukan 201).
        $response->assertOk();
        $this->assertCount(0, $response->json('data.created'));
        $this->assertCount(1, $response->json('data.failed'));
        $this->assertSame('Pasien tidak ditemukan.', $response->json('data.failed.0.reason'));
    }

    public function test_bulk_assign_duplikat_pasien_di_array_yang_kedua_dilaporkan_gagal(): void
    {
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $patient = $this->makePatient($this->puskesmasA, 1);

        Sanctum::actingAs($pj);

        $response = $this->postJson('/api/v1/visit-assignments/bulk', [
            'kader_id' => $this->kaderA->id,
            'patient_ids' => [$patient->id, $patient->id],
            'scheduled_date' => now()->addDay()->toDateString(),
            'priority' => 'berat',
        ]);

        $response->assertCreated();
        $this->assertCount(1, $response->json('data.created'));
        $this->assertCount(1, $response->json('data.failed'));
        $this->assertSame(1, VisitAssignment::where('patient_id', $patient->id)->count());
    }

    public function test_bulk_assign_ditolak_kader_beda_puskesmas(): void
    {
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $kaderB = $this->makeKader($this->puskesmasB);
        $patient = $this->makePatient($this->puskesmasA, 1);

        Sanctum::actingAs($pj);

        $response = $this->postJson('/api/v1/visit-assignments/bulk', [
            'kader_id' => $kaderB->id,
            'patient_ids' => [$patient->id],
            'scheduled_date' => now()->addDay()->toDateString(),
            'priority' => 'berat',
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, VisitAssignment::count());
    }

    public function test_kader_tidak_bisa_bulk_assign(): void
    {
        $patient = $this->makePatient($this->puskesmasA, 1);

        Sanctum::actingAs($this->kaderA->user);

        $response = $this->postJson('/api/v1/visit-assignments/bulk', [
            'kader_id' => $this->kaderA->id,
            'patient_ids' => [$patient->id],
            'scheduled_date' => now()->addDay()->toDateString(),
            'priority' => 'berat',
        ]);

        $response->assertStatus(403);
    }

    public function test_bulk_assign_tanpa_login_ditolak_401(): void
    {
        $this->postJson('/api/v1/visit-assignments/bulk', [])->assertStatus(401);
    }

    // ---- Bulk create: kunjungan berombongan (companion, docs/planning/02 §16) ----

    public function test_bulk_assign_dengan_companion_attach_ke_setiap_assignment_dan_kirim_email(): void
    {
        Mail::fake();
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $companion = $this->makeKader($this->puskesmasA);
        $patient1 = $this->makePatient($this->puskesmasA, 1);
        $patient2 = $this->makePatient($this->puskesmasA, 2);

        Sanctum::actingAs($pj);

        $response = $this->postJson('/api/v1/visit-assignments/bulk', [
            'kader_id' => $this->kaderA->id,
            'companion_kader_ids' => [$companion->id],
            'patient_ids' => [$patient1->id, $patient2->id],
            'scheduled_date' => now()->addDay()->toDateString(),
            'priority' => 'berat',
        ]);

        $response->assertCreated();
        $this->assertCount(2, $response->json('data.created'));
        $this->assertSame(2, VisitAssignmentCompanion::where('kader_id', $companion->id)->count());
        $this->assertSame($companion->id, $response->json('data.created.0.companions.0.kader_id'));

        // 1 email per kader (primer + companion) yang kena batch ini -- BUKAN 1 email per pasien.
        Mail::assertQueued(VisitAssignedMail::class, fn ($mail) => $mail->hasTo($this->kaderA->user->email) && $mail->taskCount === 2);
        Mail::assertQueued(VisitAssignedMail::class, fn ($mail) => $mail->hasTo($companion->user->email) && $mail->taskCount === 2);
        Mail::assertQueuedCount(2);
    }

    public function test_bulk_assign_companion_tidak_aktif_menolak_seluruh_batch(): void
    {
        Mail::fake();
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $companionTidakAktif = $this->makeKader($this->puskesmasA, false);
        $patient = $this->makePatient($this->puskesmasA, 1);

        Sanctum::actingAs($pj);

        $response = $this->postJson('/api/v1/visit-assignments/bulk', [
            'kader_id' => $this->kaderA->id,
            'companion_kader_ids' => [$companionTidakAktif->id],
            'patient_ids' => [$patient->id],
            'scheduled_date' => now()->addDay()->toDateString(),
            'priority' => 'berat',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, VisitAssignment::count());
        Mail::assertNothingQueued();
    }

    public function test_bulk_assign_companion_beda_puskesmas_menolak_seluruh_batch(): void
    {
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $companionBedaPuskesmas = $this->makeKader($this->puskesmasB);
        $patient = $this->makePatient($this->puskesmasA, 1);

        Sanctum::actingAs($pj);

        $response = $this->postJson('/api/v1/visit-assignments/bulk', [
            'kader_id' => $this->kaderA->id,
            'companion_kader_ids' => [$companionBedaPuskesmas->id],
            'patient_ids' => [$patient->id],
            'scheduled_date' => now()->addDay()->toDateString(),
            'priority' => 'berat',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, VisitAssignment::count());
    }

    public function test_bulk_assign_companion_tidak_boleh_sama_dengan_kader_primer(): void
    {
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $patient = $this->makePatient($this->puskesmasA, 1);

        Sanctum::actingAs($pj);

        $response = $this->postJson('/api/v1/visit-assignments/bulk', [
            'kader_id' => $this->kaderA->id,
            'companion_kader_ids' => [$this->kaderA->id],
            'patient_ids' => [$patient->id],
            'scheduled_date' => now()->addDay()->toDateString(),
            'priority' => 'berat',
        ]);

        $response->assertStatus(422);
    }

    public function test_bulk_assign_tanpa_companion_tetap_kirim_email_ke_primer_saja(): void
    {
        Mail::fake();
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $patient = $this->makePatient($this->puskesmasA, 1);

        Sanctum::actingAs($pj);

        $this->postJson('/api/v1/visit-assignments/bulk', [
            'kader_id' => $this->kaderA->id,
            'patient_ids' => [$patient->id],
            'scheduled_date' => now()->addDay()->toDateString(),
            'priority' => 'berat',
        ])->assertCreated();

        Mail::assertQueuedCount(1);
        Mail::assertQueued(VisitAssignedMail::class, fn ($mail) => $mail->hasTo($this->kaderA->user->email));
    }

    public function test_bulk_assign_semua_gagal_tidak_kirim_email(): void
    {
        Mail::fake();
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);

        Sanctum::actingAs($pj);

        $this->postJson('/api/v1/visit-assignments/bulk', [
            'kader_id' => $this->kaderA->id,
            'patient_ids' => [999999],
            'scheduled_date' => now()->addDay()->toDateString(),
            'priority' => 'berat',
        ])->assertOk();

        Mail::assertNothingQueued();
    }

    public function test_bulk_assign_tidak_kirim_email_ke_kader_yang_matikan_notifikasi(): void
    {
        // docs/planning/02 §17: VisitAssignedMail = notifikasi non-kritis, hormati
        // email_notifications_enabled -- companion yang mematikannya tidak dapat email, primer
        // (masih default true) tetap dapat.
        Mail::fake();
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $companion = $this->makeKader($this->puskesmasA);
        $companion->user->update(['email_notifications_enabled' => false]);
        $patient = $this->makePatient($this->puskesmasA, 1);

        Sanctum::actingAs($pj);

        $this->postJson('/api/v1/visit-assignments/bulk', [
            'kader_id' => $this->kaderA->id,
            'companion_kader_ids' => [$companion->id],
            'patient_ids' => [$patient->id],
            'scheduled_date' => now()->addDay()->toDateString(),
            'priority' => 'berat',
        ])->assertCreated();

        Mail::assertQueued(VisitAssignedMail::class, fn ($mail) => $mail->hasTo($this->kaderA->user->email));
        Mail::assertNotQueued(VisitAssignedMail::class, fn ($mail) => $mail->hasTo($companion->user->email));
        Mail::assertQueuedCount(1);
    }

    // ---- List: kader pendamping (docs/planning/02 §16) ----

    public function test_kader_companion_melihat_assignment_yang_didampingi_dengan_role_companion(): void
    {
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $companion = $this->makeKader($this->puskesmasA);
        $companion->user->assignRole('kader'); // makeKader() cuma buat profil, bukan role Spatie.
        $patient = $this->makePatient($this->puskesmasA, 1);

        Sanctum::actingAs($pj);
        $this->postJson('/api/v1/visit-assignments/bulk', [
            'kader_id' => $this->kaderA->id,
            'companion_kader_ids' => [$companion->id],
            'patient_ids' => [$patient->id],
            'scheduled_date' => now()->addDay()->toDateString(),
            'priority' => 'berat',
        ])->assertCreated();

        Sanctum::actingAs($companion->user);
        $response = $this->getJson('/api/v1/visit-assignments');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertCount(1, $ids);
        $this->assertSame('companion', $response->json('data.items.0.role_in_assignment'));
    }

    public function test_kader_primer_melihat_role_primary_untuk_assignment_miliknya(): void
    {
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $patient = $this->makePatient($this->puskesmasA, 1);

        Sanctum::actingAs($pj);
        $this->postJson('/api/v1/visit-assignments/bulk', [
            'kader_id' => $this->kaderA->id,
            'patient_ids' => [$patient->id],
            'scheduled_date' => now()->addDay()->toDateString(),
            'priority' => 'berat',
        ])->assertCreated();

        Sanctum::actingAs($this->kaderA->user);
        $response = $this->getJson('/api/v1/visit-assignments');

        $response->assertOk();
        $this->assertSame('primary', $response->json('data.items.0.role_in_assignment'));
    }

    // ---- List ----

    public function test_kader_hanya_melihat_assignment_miliknya_sendiri(): void
    {
        $patient1 = $this->makePatient($this->puskesmasA, 1);
        $patient2 = $this->makePatient($this->puskesmasA, 2);
        $kaderLain = $this->makeKader($this->puskesmasA);

        $milikSendiri = VisitAssignment::create([
            'patient_id' => $patient1->id, 'kader_id' => $this->kaderA->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'pending', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);
        VisitAssignment::create([
            'patient_id' => $patient2->id, 'kader_id' => $kaderLain->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'pending', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);

        Sanctum::actingAs($this->kaderA->user);

        $response = $this->getJson('/api/v1/visit-assignments');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertEquals([$milikSendiri->id], $ids->all());
    }

    public function test_admin_puskesmas_melihat_semua_assignment_di_puskesmasnya(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $patient1 = $this->makePatient($this->puskesmasA, 1);
        $patient2 = $this->makePatient($this->puskesmasB, 2);
        $kaderB = $this->makeKader($this->puskesmasB);

        $assignmentA = VisitAssignment::create([
            'patient_id' => $patient1->id, 'kader_id' => $this->kaderA->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'pending', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);
        VisitAssignment::create([
            'patient_id' => $patient2->id, 'kader_id' => $kaderB->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'pending', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasB->id,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/visit-assignments');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertEquals([$assignmentA->id], $ids->all());
    }

    public function test_filter_status(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $patient1 = $this->makePatient($this->puskesmasA, 1);
        $patient2 = $this->makePatient($this->puskesmasA, 2);

        $pending = VisitAssignment::create([
            'patient_id' => $patient1->id, 'kader_id' => $this->kaderA->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'pending', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);
        VisitAssignment::create([
            'patient_id' => $patient2->id, 'kader_id' => $this->kaderA->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'completed', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/visit-assignments?status=pending');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertEquals([$pending->id], $ids->all());
    }

    public function test_tenaga_kesehatan_hanya_melihat_assignment_miliknya_sendiri(): void
    {
        $tenagaKesehatanA = $this->makeTenagaKesehatan($this->puskesmasA);
        $tenagaKesehatanLain = $this->makeTenagaKesehatan($this->puskesmasA);
        $patient1 = $this->makePatient($this->puskesmasA, 1);
        $patient2 = $this->makePatient($this->puskesmasA, 2);

        $milikSendiri = VisitAssignment::create([
            'patient_id' => $patient1->id, 'tenaga_kesehatan_id' => $tenagaKesehatanA->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'pending', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);
        // Assignment kader murni juga ada di puskesmas yang sama -- nakes TIDAK boleh ikut lihat ini.
        VisitAssignment::create([
            'patient_id' => $patient2->id, 'kader_id' => $this->kaderA->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'pending', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);
        VisitAssignment::create([
            'patient_id' => $patient1->id, 'tenaga_kesehatan_id' => $tenagaKesehatanLain->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'pending', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);

        Sanctum::actingAs($tenagaKesehatanA->user);

        $response = $this->getJson('/api/v1/visit-assignments');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertEquals([$milikSendiri->id], $ids->all());
    }

    public function test_tanpa_login_ditolak_401(): void
    {
        $this->getJson('/api/v1/visit-assignments')->assertStatus(401);
        $this->postJson('/api/v1/visit-assignments', [])->assertStatus(401);
    }

    // ---- revisi Bu Kadis: GET /visit-assignments/{id} (halaman detail kunjungan) ----

    public function test_show_mengembalikan_detail_lengkap_termasuk_photo_url(): void
    {
        Storage::fake('s3');

        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $patient = $this->makePatient($this->puskesmasA, 1);
        $assignment = VisitAssignment::create([
            'patient_id' => $patient->id, 'kader_id' => $this->kaderA->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'completed', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);
        VisitReport::create([
            'assignment_id' => $assignment->id,
            'gps_lat' => -7.0123,
            'gps_lng' => 113.8456,
            'photo_path' => 'pasien/visit-photos/2026/08/17/dummy.jpg',
            'kondisi' => 'Kondisi baik',
            'geo_status' => 'verified',
            'sync_status' => 'synced',
            'tindakan' => ['diberi_obat'],
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/visit-assignments/{$assignment->id}");

        $response->assertOk();
        $this->assertSame($assignment->id, $response->json('data.id'));
        $this->assertSame($patient->id, $response->json('data.patient.id'));
        $this->assertSame($this->kaderA->id, $response->json('data.kader.id'));
        $this->assertSame('Kondisi baik', $response->json('data.report.kondisi'));
        $this->assertNotNull($response->json('data.report.photo_url'));
    }

    public function test_show_ditolak_untuk_assignment_beda_puskesmas(): void
    {
        $adminA = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $patientB = $this->makePatient($this->puskesmasB, 1);
        $kaderB = $this->makeKader($this->puskesmasB);
        $assignment = VisitAssignment::create([
            'patient_id' => $patientB->id, 'kader_id' => $kaderB->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'pending', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasB->id,
        ]);

        Sanctum::actingAs($adminA);

        $this->getJson("/api/v1/visit-assignments/{$assignment->id}")->assertStatus(403);
    }

    public function test_show_kader_hanya_bisa_lihat_miliknya_sendiri(): void
    {
        $kaderLain = $this->makeKader($this->puskesmasA);
        $patient = $this->makePatient($this->puskesmasA, 1);
        $milikLain = VisitAssignment::create([
            'patient_id' => $patient->id, 'kader_id' => $kaderLain->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'pending', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);

        Sanctum::actingAs($this->kaderA->user);

        $this->getJson("/api/v1/visit-assignments/{$milikLain->id}")->assertStatus(403);
    }

    // ---- revisi Bu Kadis: GET /visit-assignments/monitoring ----

    public function test_monitoring_menghitung_status_dan_tenggat_lewat(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $patient = $this->makePatient($this->puskesmasA, 1);

        VisitAssignment::create([
            'patient_id' => $patient->id, 'kader_id' => $this->kaderA->id,
            'scheduled_date' => now()->subDays(3)->toDateString(), 'status' => 'pending', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);
        VisitAssignment::create([
            'patient_id' => $patient->id, 'kader_id' => $this->kaderA->id,
            'scheduled_date' => now()->addDay()->toDateString(), 'status' => 'pending', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);
        VisitAssignment::create([
            'patient_id' => $patient->id, 'kader_id' => $this->kaderA->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'in_progress', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);
        VisitAssignment::create([
            'patient_id' => $patient->id, 'kader_id' => $this->kaderA->id,
            'scheduled_date' => now()->subDays(5)->toDateString(), 'status' => 'completed', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/visit-assignments/monitoring');

        $response->assertOk();
        $this->assertSame(2, $response->json('data.summary.pending'));
        $this->assertSame(1, $response->json('data.summary.in_progress'));
        $this->assertSame(1, $response->json('data.summary.completed'));
        // 1 pending yang scheduled_date-nya sudah lewat -- BUKAN 2, yang addDay() belum lewat.
        $this->assertSame(1, $response->json('data.summary.overdue'));
    }

    public function test_monitoring_per_desa_mengelompokkan_kunjungan_dan_petugas(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $kabupaten = Kabupaten::first();
        $kecamatan = Kecamatan::create(['kabupaten_id' => $kabupaten->id, 'kode_kemendagri' => 'K01', 'nama' => 'Ambunten']);
        $desaSatu = Desa::create(['kecamatan_id' => $kecamatan->id, 'puskesmas_id' => $this->puskesmasA->id, 'kode_kemendagri' => 'D01', 'nama' => 'Ambunten Barat']);
        $desaDua = Desa::create(['kecamatan_id' => $kecamatan->id, 'puskesmas_id' => $this->puskesmasA->id, 'kode_kemendagri' => 'D02', 'nama' => 'Ambunten Timur']);

        $kaderKedua = $this->makeKader($this->puskesmasA);

        $pasienSatu = $this->makePatient($this->puskesmasA, 1, ['desa_id' => $desaSatu->id]);
        $pasienDua = $this->makePatient($this->puskesmasA, 2, ['desa_id' => $desaSatu->id]);
        $pasienTiga = $this->makePatient($this->puskesmasA, 3, ['desa_id' => $desaDua->id]);
        // Belum resolved ke desa mana pun -- TIDAK ikut breakdown per_desa (tetap ikut summary).
        $pasienTanpaDesa = $this->makePatient($this->puskesmasA, 4);

        VisitAssignment::create([
            'patient_id' => $pasienSatu->id, 'kader_id' => $this->kaderA->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'pending', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);
        VisitAssignment::create([
            'patient_id' => $pasienDua->id, 'kader_id' => $kaderKedua->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'completed', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);
        VisitAssignment::create([
            'patient_id' => $pasienTiga->id, 'kader_id' => $this->kaderA->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'pending', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);
        VisitAssignment::create([
            'patient_id' => $pasienTanpaDesa->id, 'kader_id' => $this->kaderA->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'pending', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/visit-assignments/monitoring');

        $response->assertOk();
        // 4 assignment total di summary, TAPI cuma 3 masuk per_desa (1 pasien belum resolved desa).
        $this->assertSame(3, $response->json('data.summary.pending'));
        $perDesa = collect($response->json('data.per_desa'))->keyBy('desa_id');
        $this->assertCount(2, $perDesa);

        $satu = $perDesa[$desaSatu->id];
        $this->assertSame('Ambunten Barat', $satu['desa_nama']);
        $this->assertSame(2, $satu['total']);
        $this->assertSame(1, $satu['pending']);
        $this->assertSame(1, $satu['completed']);
        $this->assertCount(2, $satu['petugas']); // kaderA + kaderKedua, dua-duanya berbeda

        $dua = $perDesa[$desaDua->id];
        $this->assertSame('Ambunten Timur', $dua['desa_nama']);
        $this->assertSame(1, $dua['total']);
        $this->assertSame(1, $dua['pending']);
    }

    private function makeKader(Puskesmas $puskesmas, bool $aktif = true): Kader
    {
        static $n = 100;
        $n++;
        $user = User::factory()->create(['puskesmas_id' => $puskesmas->id, 'email' => "kader{$n}@example.test"]);

        return Kader::create(['user_id' => $user->id, 'puskesmas_id' => $puskesmas->id, 'status_aktif' => $aktif]);
    }

    private function makeTenagaKesehatan(Puskesmas $puskesmas, bool $aktif = true): TenagaKesehatan
    {
        static $n = 200;
        $n++;
        $user = User::factory()->create(['puskesmas_id' => $puskesmas->id, 'email' => "tenagakesehatan{$n}@example.test"]);
        $user->assignRole('tenaga_kesehatan');

        return TenagaKesehatan::create(['user_id' => $user->id, 'puskesmas_id' => $puskesmas->id, 'status_aktif' => $aktif]);
    }
}
