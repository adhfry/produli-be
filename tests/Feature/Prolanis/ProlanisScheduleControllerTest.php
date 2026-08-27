<?php

namespace Tests\Feature\Prolanis;

use App\Models\Kabupaten;
use App\Models\PatientsCache;
use App\Models\ProlanisSchedule;
use App\Models\Puskesmas;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProlanisScheduleControllerTest extends TestCase
{
    use RefreshDatabase;

    private Puskesmas $puskesmasA;

    private Puskesmas $puskesmasB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);

        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $this->puskesmasA = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-A', 'nama' => 'Puskesmas A']);
        $this->puskesmasB = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-B', 'nama' => 'Puskesmas B']);
    }

    private function makeUser(string $role, ?Puskesmas $puskesmas = null): User
    {
        $user = User::factory()->create(['puskesmas_id' => $puskesmas?->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makeSchedule(Puskesmas $puskesmas, string $date, int $externalId): ProlanisSchedule
    {
        $patient = PatientsCache::create([
            'external_patient_id' => $externalId, 'nik_hash' => 'H-'.$externalId, 'nama' => 'Pasien '.$externalId,
            'is_prolanis' => true, 'jenis_prolanis' => 'DM', 'puskesmas_id' => $puskesmas->id, 'wilayah_status' => 'unknown',
        ]);

        return ProlanisSchedule::create([
            'patient_id' => $patient->id, 'puskesmas_id' => $puskesmas->id, 'jenis_prolanis' => 'DM', 'scheduled_date' => $date,
        ]);
    }

    public function test_admin_puskesmas_hanya_lihat_jadwal_puskesmasnya_sendiri(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $scheduleA = $this->makeSchedule($this->puskesmasA, '2026-09-01', 1);
        $this->makeSchedule($this->puskesmasB, '2026-09-01', 2);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/jadwal-prolanis');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($scheduleA->id, $response->json('data.0.id'));
    }

    public function test_super_admin_lihat_semua_puskesmas(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $this->makeSchedule($this->puskesmasA, '2026-09-01', 1);
        $this->makeSchedule($this->puskesmasB, '2026-09-01', 2);

        Sanctum::actingAs($superAdmin);
        $response = $this->getJson('/api/v1/jadwal-prolanis');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_filter_date_range(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $this->makeSchedule($this->puskesmasA, '2026-09-01', 1);
        $this->makeSchedule($this->puskesmasA, '2026-12-01', 2);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/jadwal-prolanis?date_from=2026-08-01&date_to=2026-10-01');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_admin_berhasil_reschedule_jadwal_puskesmasnya(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $schedule = $this->makeSchedule($this->puskesmasA, '2026-09-01', 1);

        Sanctum::actingAs($admin);
        $response = $this->patchJson("/api/v1/jadwal-prolanis/{$schedule->id}/reschedule", ['scheduled_date' => '2026-09-15']);

        $response->assertOk();
        $this->assertSame('2026-09-15', $schedule->fresh()->scheduled_date->toDateString());
        $this->assertTrue($schedule->fresh()->is_manual_override);
    }

    public function test_admin_beda_puskesmas_ditolak_reschedule(): void
    {
        $adminB = $this->makeUser('admin_puskesmas', $this->puskesmasB);
        $schedule = $this->makeSchedule($this->puskesmasA, '2026-09-01', 1);

        Sanctum::actingAs($adminB);
        $response = $this->patchJson("/api/v1/jadwal-prolanis/{$schedule->id}/reschedule", ['scheduled_date' => '2026-09-15']);

        $response->assertStatus(403);
    }

    public function test_update_status_ke_selesai(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $schedule = $this->makeSchedule($this->puskesmasA, '2026-09-01', 1);

        Sanctum::actingAs($admin);
        $response = $this->patchJson("/api/v1/jadwal-prolanis/{$schedule->id}/status", ['status' => 'selesai']);

        $response->assertOk();
        $this->assertSame('selesai', $schedule->fresh()->status);
    }

    public function test_status_tidak_valid_ditolak_422(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $schedule = $this->makeSchedule($this->puskesmasA, '2026-09-01', 1);

        Sanctum::actingAs($admin);
        $response = $this->patchJson("/api/v1/jadwal-prolanis/{$schedule->id}/status", ['status' => 'ngasal']);

        $response->assertStatus(422);
    }

    public function test_kader_ditolak_akses_jadwal(): void
    {
        $kaderUser = User::factory()->create(['puskesmas_id' => $this->puskesmasA->id]);
        $kaderUser->assignRole('kader');

        Sanctum::actingAs($kaderUser);
        $this->getJson('/api/v1/jadwal-prolanis')->assertStatus(403);
    }

    public function test_tanpa_login_ditolak_401(): void
    {
        $this->getJson('/api/v1/jadwal-prolanis')->assertStatus(401);
    }
}
