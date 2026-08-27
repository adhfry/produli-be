<?php

namespace Tests\Feature\Visit;

use App\Models\Kabupaten;
use App\Models\Kader;
use App\Models\PatientsCache;
use App\Models\Puskesmas;
use App\Models\TenagaKesehatan;
use App\Models\User;
use App\Models\VisitAssignment;
use App\Models\VisitReport;
use App\Notifications\GenericDatabaseNotification;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresi untuk GET /api/v1/rujukan & PATCH /api/v1/rujukan/{visitReport}/konfirmasi (Fase 3,
 * docs plan "cozy-mapping-breeze") -- scoping ter-otorisasi lewat puskesmas KADER/NAKES pelapor
 * (VisitReport.assignment.kader.puskesmas_id / .tenagaKesehatan.puskesmas_id), BUKAN
 * puskesmas_id_snapshot assignment (itu turunan puskesmas PASIEN) -- konsisten dengan
 * VisitReportService::notifyPasienDirujuk() (Fase 1/2).
 */
class RujukanControllerTest extends TestCase
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

        $kaderUserA = User::factory()->create(['puskesmas_id' => $this->puskesmasA->id, 'name' => 'Bu Siti']);
        $kaderUserA->assignRole('kader');
        $this->kaderA = Kader::create(['user_id' => $kaderUserA->id, 'puskesmas_id' => $this->puskesmasA->id, 'status_aktif' => true]);
    }

    private function makeUser(string $role, ?Puskesmas $puskesmas = null): User
    {
        $user = User::factory()->create(['puskesmas_id' => $puskesmas?->id]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $reportOverrides
     */
    private function makeRujukan(Kader $kader, Puskesmas $patientPuskesmas, array $reportOverrides = []): VisitReport
    {
        $patient = PatientsCache::create([
            'external_patient_id' => random_int(100000, 999999),
            'nik_hash' => 'HASH-'.uniqid(),
            'nama' => 'Pasien Rujukan',
            'puskesmas_id' => $patientPuskesmas->id,
            'wilayah_status' => 'resolved',
        ]);

        $assignment = VisitAssignment::create([
            'patient_id' => $patient->id,
            'kader_id' => $kader->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'completed',
            'priority' => 'sedang',
            // SENGAJA puskesmas PASIEN, bisa beda dari puskesmas kader -- membuktikan scoping
            // ikut kader, bukan snapshot ini.
            'puskesmas_id_snapshot' => $patientPuskesmas->id,
        ]);

        return VisitReport::create(array_merge([
            'assignment_id' => $assignment->id,
            'kondisi' => 'Kondisi stabil.',
            'photo_path' => 'pasien/visit-photos/dummy.jpg',
            'gps_lat' => -7.0123,
            'gps_lng' => 113.8456,
            'geo_status' => 'verified',
            'sync_status' => 'synced',
            'tindakan' => ['dirujuk_puskesmas'],
            'cara_rujukan' => 'datang_sendiri',
            'rujukan_status' => 'menunggu_konfirmasi',
        ], $reportOverrides));
    }

    // ---- GET /rujukan ----

    public function test_admin_puskesmas_hanya_lihat_rujukan_dari_kader_puskesmasnya_sendiri(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);

        // Rujukan dari kader puskesmas A (walau pasiennya puskesmas B) -- HARUS muncul.
        $rujukanA = $this->makeRujukan($this->kaderA, $this->puskesmasB);

        // Rujukan dari kader puskesmas B -- TIDAK boleh muncul.
        $kaderUserB = User::factory()->create(['puskesmas_id' => $this->puskesmasB->id]);
        $kaderUserB->assignRole('kader');
        $kaderB = Kader::create(['user_id' => $kaderUserB->id, 'puskesmas_id' => $this->puskesmasB->id, 'status_aktif' => true]);
        $this->makeRujukan($kaderB, $this->puskesmasB);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/rujukan');

        $response->assertOk();
        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame($rujukanA->id, $items[0]['id']);
        $this->assertSame('Puskesmas A', $items[0]['puskesmas']['nama']);
    }

    public function test_super_admin_lihat_semua_rujukan(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $this->makeRujukan($this->kaderA, $this->puskesmasA);

        $kaderUserB = User::factory()->create(['puskesmas_id' => $this->puskesmasB->id]);
        $kaderUserB->assignRole('kader');
        $kaderB = Kader::create(['user_id' => $kaderUserB->id, 'puskesmas_id' => $this->puskesmasB->id, 'status_aktif' => true]);
        $this->makeRujukan($kaderB, $this->puskesmasB);

        Sanctum::actingAs($superAdmin);

        $response = $this->getJson('/api/v1/rujukan');

        $response->assertOk();
        $this->assertCount(2, $response->json('data.items'));
    }

    public function test_laporan_kunjungan_biasa_tanpa_rujukan_tidak_ikut_muncul(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $this->makeRujukan($this->kaderA, $this->puskesmasA, ['rujukan_status' => null, 'cara_rujukan' => null, 'tindakan' => ['diberi_obat']]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/rujukan');

        $response->assertOk();
        $this->assertCount(0, $response->json('data.items'));
    }

    public function test_kader_tidak_boleh_akses_daftar_rujukan(): void
    {
        Sanctum::actingAs($this->kaderA->user);

        $this->getJson('/api/v1/rujukan')->assertStatus(403);
    }

    public function test_rujukan_dari_tenaga_kesehatan_ikut_ter_scope_puskesmasnya(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);

        $tkUser = User::factory()->create(['puskesmas_id' => $this->puskesmasA->id, 'name' => 'Pak Budi']);
        $tkUser->assignRole('tenaga_kesehatan');
        $tk = TenagaKesehatan::create(['user_id' => $tkUser->id, 'puskesmas_id' => $this->puskesmasA->id, 'status_aktif' => true]);

        $patient = PatientsCache::create([
            'external_patient_id' => 555001,
            'nik_hash' => 'HASH-555001',
            'nama' => 'Pasien TK',
            'puskesmas_id' => $this->puskesmasA->id,
            'wilayah_status' => 'resolved',
        ]);
        $assignment = VisitAssignment::create([
            'patient_id' => $patient->id,
            'tenaga_kesehatan_id' => $tk->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'completed',
            'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);
        VisitReport::create([
            'assignment_id' => $assignment->id,
            'kondisi' => 'Kondisi stabil.',
            'photo_path' => 'pasien/visit-photos/dummy.jpg',
            'gps_lat' => -7.0123,
            'gps_lng' => 113.8456,
            'geo_status' => 'verified',
            'sync_status' => 'synced',
            'tindakan' => ['dirujuk_puskesmas'],
            'cara_rujukan' => 'dijemput_ambulan',
            'rujukan_status' => 'menunggu_konfirmasi',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/rujukan');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame('tenaga_kesehatan', $response->json('data.items.0.petugas.tipe'));
        $this->assertSame('Pak Budi', $response->json('data.items.0.petugas.nama'));
    }

    // ---- PATCH /rujukan/{id}/konfirmasi ----

    public function test_admin_puskesmas_berhasil_konfirmasi_rujukan(): void
    {
        Notification::fake();
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $rujukan = $this->makeRujukan($this->kaderA, $this->puskesmasA);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/v1/rujukan/{$rujukan->id}/konfirmasi", ['status' => 'dikonfirmasi']);

        $response->assertOk();
        $this->assertSame('dikonfirmasi', $rujukan->fresh()->rujukan_status);

        // GAP yang diperbaiki: sebelumnya konfirmasi/batalkan TIDAK PERNAH menotif balik kader/
        // nakes pelapor -- mereka cuma tahu lewat cek manual, menggagalkan tujuan alur rujukan.
        Notification::assertSentTo(
            $this->kaderA->user,
            GenericDatabaseNotification::class,
            fn ($notification) => $notification->toDatabase($this->kaderA->user)['type'] === 'rujukan_dikonfirmasi'
                && $notification->toDatabase($this->kaderA->user)['rujukan_status'] === 'dikonfirmasi'
        );
    }

    public function test_pj_prolanis_berhasil_membatalkan_rujukan(): void
    {
        Notification::fake();
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $rujukan = $this->makeRujukan($this->kaderA, $this->puskesmasA);

        Sanctum::actingAs($pj);

        $response = $this->patchJson("/api/v1/rujukan/{$rujukan->id}/konfirmasi", ['status' => 'dibatalkan']);

        $response->assertOk();
        $this->assertSame('dibatalkan', $rujukan->fresh()->rujukan_status);

        Notification::assertSentTo(
            $this->kaderA->user,
            GenericDatabaseNotification::class,
            fn ($notification) => $notification->toDatabase($this->kaderA->user)['type'] === 'rujukan_dikonfirmasi'
                && $notification->toDatabase($this->kaderA->user)['rujukan_status'] === 'dibatalkan'
        );
    }

    public function test_admin_beda_puskesmas_ditolak_konfirmasi_rujukan(): void
    {
        $adminB = $this->makeUser('admin_puskesmas', $this->puskesmasB);
        $rujukan = $this->makeRujukan($this->kaderA, $this->puskesmasA);

        Sanctum::actingAs($adminB);

        $response = $this->patchJson("/api/v1/rujukan/{$rujukan->id}/konfirmasi", ['status' => 'dikonfirmasi']);

        $response->assertStatus(403);
        $this->assertSame('menunggu_konfirmasi', $rujukan->fresh()->rujukan_status);
    }

    public function test_super_admin_tidak_bisa_konfirmasi_rujukan(): void
    {
        // Plan eksplisit: "hanya admin_puskesmas/pj_prolanis puskesmas terkait" -- super_admin
        // cuma bisa MELIHAT (viewAnyRujukan), bukan mengonfirmasi.
        $superAdmin = $this->makeUser('super_admin');
        $rujukan = $this->makeRujukan($this->kaderA, $this->puskesmasA);

        Sanctum::actingAs($superAdmin);

        $response = $this->patchJson("/api/v1/rujukan/{$rujukan->id}/konfirmasi", ['status' => 'dikonfirmasi']);

        $response->assertStatus(403);
    }

    public function test_konfirmasi_laporan_yang_bukan_rujukan_ditolak(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $laporanBiasa = $this->makeRujukan($this->kaderA, $this->puskesmasA, [
            'rujukan_status' => null, 'cara_rujukan' => null, 'tindakan' => ['diberi_obat'],
        ]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/v1/rujukan/{$laporanBiasa->id}/konfirmasi", ['status' => 'dikonfirmasi']);

        $response->assertStatus(422);
    }

    public function test_status_konfirmasi_wajib_nilai_valid(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $rujukan = $this->makeRujukan($this->kaderA, $this->puskesmasA);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/v1/rujukan/{$rujukan->id}/konfirmasi", ['status' => 'ngasal']);

        $response->assertStatus(422);
    }

    public function test_tanpa_login_ditolak_401(): void
    {
        $this->getJson('/api/v1/rujukan')->assertStatus(401);
    }

    public function test_konfirmasi_mencatat_waktu_dan_pelaku(): void
    {
        // Permintaan user -- sebelumnya siapa & kapan konfirmasi diambil TIDAK PERNAH terekam.
        Notification::fake();
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $rujukan = $this->makeRujukan($this->kaderA, $this->puskesmasA);

        Sanctum::actingAs($admin);
        $this->travelTo(now()->setTime(10, 30));

        $response = $this->patchJson("/api/v1/rujukan/{$rujukan->id}/konfirmasi", ['status' => 'dikonfirmasi']);

        $response->assertOk();
        $this->assertSame($admin->id, $response->json('data.confirmed_by.id'));
        $this->assertNotNull($response->json('data.confirmed_at'));
        $fresh = $rujukan->fresh();
        $this->assertSame($admin->id, $fresh->confirmed_by);
        $this->assertTrue($fresh->confirmed_at->equalTo(now()));
    }

    public function test_pembatalan_juga_mencatat_waktu_dan_pelaku(): void
    {
        Notification::fake();
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $rujukan = $this->makeRujukan($this->kaderA, $this->puskesmasA);

        Sanctum::actingAs($pj);

        $response = $this->patchJson("/api/v1/rujukan/{$rujukan->id}/konfirmasi", ['status' => 'dibatalkan']);

        $response->assertOk();
        $this->assertSame($pj->id, $response->json('data.confirmed_by.id'));
        $this->assertNotNull($response->json('data.confirmed_at'));
    }

    // ---- PATCH /rujukan/{id}/tindakan-lanjutan ----

    public function test_admin_puskesmas_berhasil_input_tindakan_lanjutan_setelah_dikonfirmasi(): void
    {
        Notification::fake();
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $rujukan = $this->makeRujukan($this->kaderA, $this->puskesmasA, ['rujukan_status' => 'dikonfirmasi']);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/v1/rujukan/{$rujukan->id}/tindakan-lanjutan", [
            'tindakan_puskesmas' => ['edukasi', 'obat_tambahan'],
            'catatan' => 'Tekanan darah terkontrol, diberi edukasi pola makan.',
        ]);

        $response->assertOk();
        $this->assertSame(['edukasi', 'obat_tambahan'], $response->json('data.tindakan_puskesmas'));
        $this->assertSame('Tekanan darah terkontrol, diberi edukasi pola makan.', $response->json('data.catatan_tindakan_puskesmas'));
        $this->assertSame($admin->id, $response->json('data.tindakan_puskesmas_by.id'));
        $this->assertNotNull($response->json('data.tindakan_puskesmas_at'));

        $fresh = $rujukan->fresh();
        $this->assertSame(['edukasi', 'obat_tambahan'], $fresh->tindakan_puskesmas);
        $this->assertSame($admin->id, $fresh->tindakan_puskesmas_by);
    }

    public function test_tindakan_lanjutan_ditolak_kalau_belum_dikonfirmasi(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $rujukan = $this->makeRujukan($this->kaderA, $this->puskesmasA); // masih menunggu_konfirmasi

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/v1/rujukan/{$rujukan->id}/tindakan-lanjutan", [
            'tindakan_puskesmas' => ['rawat_inap'],
        ]);

        $response->assertStatus(422);
        $this->assertNull($rujukan->fresh()->tindakan_puskesmas);
    }

    public function test_tindakan_lanjutan_ditolak_kalau_rujukan_dibatalkan(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $rujukan = $this->makeRujukan($this->kaderA, $this->puskesmasA, ['rujukan_status' => 'dibatalkan']);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/v1/rujukan/{$rujukan->id}/tindakan-lanjutan", [
            'tindakan_puskesmas' => ['rawat_inap'],
        ]);

        $response->assertStatus(422);
    }

    public function test_tindakan_lanjutan_admin_beda_puskesmas_ditolak_403(): void
    {
        $adminB = $this->makeUser('admin_puskesmas', $this->puskesmasB);
        $rujukan = $this->makeRujukan($this->kaderA, $this->puskesmasA, ['rujukan_status' => 'dikonfirmasi']);

        Sanctum::actingAs($adminB);

        $response = $this->patchJson("/api/v1/rujukan/{$rujukan->id}/tindakan-lanjutan", [
            'tindakan_puskesmas' => ['rawat_inap'],
        ]);

        $response->assertStatus(403);
    }

    public function test_tindakan_lanjutan_nilai_tidak_valid_ditolak_422(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $rujukan = $this->makeRujukan($this->kaderA, $this->puskesmasA, ['rujukan_status' => 'dikonfirmasi']);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/v1/rujukan/{$rujukan->id}/tindakan-lanjutan", [
            'tindakan_puskesmas' => ['ngasal'],
        ]);

        $response->assertStatus(422);
    }

    public function test_tindakan_lanjutan_boleh_diisi_ulang_menimpa_yang_lama(): void
    {
        // Permintaan user: diagnosa awal bisa berubah setelah observasi lanjut -- admin harus
        // bisa mengoreksi, bukan ditolak krn "sudah pernah diisi".
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $rujukan = $this->makeRujukan($this->kaderA, $this->puskesmasA, [
            'rujukan_status' => 'dikonfirmasi',
            'tindakan_puskesmas' => ['edukasi'],
            'catatan_tindakan_puskesmas' => 'Catatan awal.',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/v1/rujukan/{$rujukan->id}/tindakan-lanjutan", [
            'tindakan_puskesmas' => ['rawat_inap'],
            'catatan' => 'Dirujuk rawat inap setelah observasi.',
        ]);

        $response->assertOk();
        $this->assertSame(['rawat_inap'], $rujukan->fresh()->tindakan_puskesmas);
        $this->assertSame('Dirujuk rawat inap setelah observasi.', $rujukan->fresh()->catatan_tindakan_puskesmas);
    }
}
