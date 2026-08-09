<?php

namespace Tests\Feature\Visit;

use App\Models\Kabupaten;
use App\Models\Kader;
use App\Models\PatientsCache;
use App\Models\Puskesmas;
use App\Models\User;
use App\Models\VisitAssignment;
use App\Models\VisitReport;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresi untuk alur review & validasi laporan kunjungan (docs/planning/02 §11):
 * PATCH /api/v1/visit-reports/{id}/accept (pj_prolanis, scoped ke kader miliknya sendiri,
 * idempotent) dan PATCH /api/v1/validasi-laporan/{id} (super_admin, kapan pun, boleh dikoreksi).
 */
class VisitReportReviewTest extends TestCase
{
    use RefreshDatabase;

    private VisitReport $report;

    private User $pj;

    private Kader $kader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $puskesmas = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-A', 'nama' => 'Puskesmas A']);

        $this->pj = User::factory()->create(['puskesmas_id' => $puskesmas->id, 'name' => 'Bu PJ']);
        $this->pj->assignRole('pj_prolanis');

        $kaderUser = User::factory()->create(['puskesmas_id' => $puskesmas->id, 'name' => 'Bu Siti']);
        $kaderUser->assignRole('kader');
        $this->kader = Kader::create([
            'user_id' => $kaderUser->id,
            'pj_id' => $this->pj->id,
            'puskesmas_id' => $puskesmas->id,
            'status_aktif' => true,
        ]);

        $patient = PatientsCache::create([
            'external_patient_id' => 990002,
            'nik_hash' => 'HASH-990002',
            'nama' => 'Pasien Uji',
            'wilayah_status' => 'resolved',
            'puskesmas_id' => $puskesmas->id,
            'geo_status' => 'verified',
            'latitude' => -7.0123,
            'longitude' => 113.8456,
        ]);

        $assignment = VisitAssignment::create([
            'patient_id' => $patient->id,
            'kader_id' => $this->kader->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'completed',
            'priority' => 'sedang',
            'puskesmas_id_snapshot' => $puskesmas->id,
        ]);

        $this->report = VisitReport::create([
            'assignment_id' => $assignment->id,
            'gps_lat' => -7.0123,
            'gps_lng' => 113.8456,
            'photo_path' => 'pasien/visit-photos/2026/08/06/dummy.jpg',
            'kondisi' => 'Kondisi stabil.',
            'geo_status' => 'verified',
            'latitude' => -7.0123,
            'longitude' => 113.8456,
            'face_detected' => true,
            'sync_status' => 'synced',
        ]);
    }

    public function test_pj_prolanis_berhasil_menerima_laporan_kadernya_sendiri(): void
    {
        Sanctum::actingAs($this->pj);

        $response = $this->patchJson("/api/v1/visit-reports/{$this->report->id}/accept");

        $response->assertOk();
        $this->assertSame('success', $response->json('status'));

        $this->report->refresh();
        $this->assertNotNull($this->report->pj_reviewed_at);
        $this->assertSame($this->pj->id, $this->report->pj_reviewed_by);
    }

    public function test_menerima_laporan_idempotent_tidak_menimpa_waktu_terima_pertama(): void
    {
        Sanctum::actingAs($this->pj);

        $this->patchJson("/api/v1/visit-reports/{$this->report->id}/accept")->assertOk();
        $firstReviewedAt = $this->report->fresh()->pj_reviewed_at;

        $this->travel(1)->hours();

        $this->patchJson("/api/v1/visit-reports/{$this->report->id}/accept")->assertOk();
        $secondReviewedAt = $this->report->fresh()->pj_reviewed_at;

        $this->assertTrue($firstReviewedAt->equalTo($secondReviewedAt));
    }

    public function test_pj_prolanis_tidak_bisa_menerima_laporan_kader_bukan_miliknya(): void
    {
        $pjLain = User::factory()->create();
        $pjLain->assignRole('pj_prolanis');

        Sanctum::actingAs($pjLain);

        $response = $this->patchJson("/api/v1/visit-reports/{$this->report->id}/accept");

        $response->assertStatus(403);
        $this->assertNull($this->report->fresh()->pj_reviewed_at);
    }

    public function test_bukan_pj_prolanis_ditolak_menerima_laporan(): void
    {
        Sanctum::actingAs($this->kader->user);

        $response = $this->patchJson("/api/v1/visit-reports/{$this->report->id}/accept");

        $response->assertStatus(403);
    }

    public function test_super_admin_berhasil_validasi_laporan_valid(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        Sanctum::actingAs($superAdmin);

        $response = $this->patchJson("/api/v1/validasi-laporan/{$this->report->id}", [
            'is_valid' => true,
            'note' => 'Data lengkap dan sesuai.',
        ]);

        $response->assertOk();
        $this->report->refresh();
        $this->assertSame('valid', $this->report->validation_status);
        $this->assertSame($superAdmin->id, $this->report->validated_by);
        $this->assertSame('Data lengkap dan sesuai.', $this->report->validation_note);
        $this->assertNotNull($this->report->validated_at);
    }

    public function test_super_admin_bisa_validasi_tanpa_menunggu_pj_menerima(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        Sanctum::actingAs($superAdmin);

        $this->assertNull($this->report->pj_reviewed_at);

        $response = $this->patchJson("/api/v1/validasi-laporan/{$this->report->id}", [
            'is_valid' => false,
        ]);

        $response->assertOk();
        $this->assertSame('invalid', $this->report->fresh()->validation_status);
    }

    public function test_validasi_tidak_valid_mengembalikan_assignment_ke_pending_dan_kirim_notifikasi_ke_kader(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        Sanctum::actingAs($superAdmin);

        $this->assertSame('completed', $this->report->assignment->status);

        $response = $this->patchJson("/api/v1/validasi-laporan/{$this->report->id}", [
            'is_valid' => false,
            'note' => 'Foto tidak jelas, ulangi kunjungan.',
        ]);

        $response->assertOk();

        // Assignment dibuka lagi (bukan status baru), laporan lama TETAP ada sebagai jejak audit.
        $this->assertSame('pending', $this->report->assignment->fresh()->status);
        $this->assertNotNull(VisitReport::find($this->report->id));

        $notification = $this->kader->user->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertSame('visit_report_invalidated', $notification->data['type']);
        $this->assertSame($this->report->id, $notification->data['visit_report_id']);
        $this->assertSame('Foto tidak jelas, ulangi kunjungan.', $notification->data['validation_note']);
    }

    public function test_validasi_valid_tidak_mengubah_status_assignment_dan_tidak_kirim_notifikasi(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        Sanctum::actingAs($superAdmin);

        $this->patchJson("/api/v1/validasi-laporan/{$this->report->id}", ['is_valid' => true])->assertOk();

        // Status assignment yang sudah completed TIDAK ikut berubah -- cuma invalid yang buka lagi.
        $this->assertSame('completed', $this->report->assignment->fresh()->status);
        $this->assertSame(0, $this->kader->user->notifications()->count());
    }

    public function test_super_admin_bisa_mengoreksi_validasi_sebelumnya(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        Sanctum::actingAs($superAdmin);

        $this->patchJson("/api/v1/validasi-laporan/{$this->report->id}", ['is_valid' => false])->assertOk();
        $this->assertSame('invalid', $this->report->fresh()->validation_status);

        $this->patchJson("/api/v1/validasi-laporan/{$this->report->id}", ['is_valid' => true])->assertOk();
        $this->assertSame('valid', $this->report->fresh()->validation_status);
    }

    public function test_bukan_super_admin_ditolak_validasi_laporan(): void
    {
        Sanctum::actingAs($this->pj);

        $response = $this->patchJson("/api/v1/validasi-laporan/{$this->report->id}", ['is_valid' => true]);

        $response->assertStatus(403);
    }

    public function test_validasi_laporan_wajib_is_valid_boolean(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        Sanctum::actingAs($superAdmin);

        $response = $this->patchJson("/api/v1/validasi-laporan/{$this->report->id}", []);

        $response->assertStatus(422);
    }

    public function test_tanpa_login_ditolak_401(): void
    {
        $this->patchJson("/api/v1/visit-reports/{$this->report->id}/accept")->assertStatus(401);
        $this->patchJson("/api/v1/validasi-laporan/{$this->report->id}", ['is_valid' => true])->assertStatus(401);
    }
}
