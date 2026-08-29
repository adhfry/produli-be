<?php

namespace Tests\Feature\PengirimanSampel;

use App\Models\Kabupaten;
use App\Models\PatientsCache;
use App\Models\PengirimanSampel;
use App\Models\Puskesmas;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase B modul "Kirim Data Prolanis ke Labkesda Sumenep" -- penyusun antrian murni dalam
 * PRODULI. Lihat docblock PengirimanSampelService untuk state-machine yang diuji di sini.
 */
class PengirimanSampelControllerTest extends TestCase
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

    private function makeProlanisPatient(Puskesmas $puskesmas, array $overrides = []): PatientsCache
    {
        static $n = 0;
        $n++;

        return PatientsCache::create(array_merge([
            'external_patient_id' => 900000 + $n,
            'nik_hash' => "HASH-PS-{$n}",
            'nama' => "Pasien Prolanis {$n}",
            'puskesmas_id' => $puskesmas->id,
            'wilayah_status' => 'resolved',
            'is_prolanis' => true,
            'jenis_prolanis' => 'HT',
        ], $overrides));
    }

    private function makeBatch(Puskesmas $puskesmas, User $creator): PengirimanSampel
    {
        return PengirimanSampel::create([
            'puskesmas_id' => $puskesmas->id,
            'status' => 'draft',
            'dibuat_oleh' => $creator->id,
        ]);
    }

    // ---- Create ----

    public function test_admin_puskesmas_bikin_antrian_baru_berstatus_draft(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/pengiriman-sampel');

        $response->assertCreated();
        $this->assertSame('draft', $response->json('data.status'));
        $this->assertSame($this->puskesmasA->id, $response->json('data.puskesmas.id'));
    }

    // ---- Tambah pasien ----

    public function test_tambah_pasien_existing_ke_antrian(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $batch = $this->makeBatch($this->puskesmasA, $admin);
        $patient = $this->makeProlanisPatient($this->puskesmasA);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/pasien", [
            'external_patient_id' => $patient->external_patient_id,
        ]);

        $response->assertCreated();
        $this->assertSame(1, $batch->pasien()->count());
        $this->assertSame($patient->nama, $batch->pasien()->first()->nama_snapshot);
        $this->assertFalse($response->json('data.is_pasien_baru'));
    }

    public function test_tambah_pasien_baru_wajib_identitas_lengkap(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $batch = $this->makeBatch($this->puskesmasA, $admin);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/pasien", [
            'name' => 'Siti Aminah',
        ]);

        $response->assertStatus(422);
    }

    public function test_tambah_pasien_baru_lengkap_berhasil(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $batch = $this->makeBatch($this->puskesmasA, $admin);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/pasien", [
            'name' => 'Siti Aminah',
            'nik' => '3529010101650001',
            'gender' => 'P',
            'tempat_lahir' => 'Sumenep',
            'tgl_lahir' => '1965-01-01',
            'phone' => '081234567890',
            'alamat' => 'Jl. Uji No. 1',
            'jenis_prolanis' => 'HT',
        ]);

        $response->assertCreated();
        $this->assertTrue($response->json('data.is_pasien_baru'));
        $this->assertNull($batch->pasien()->first()->external_patient_id);
    }

    public function test_tambah_pasien_ditolak_kalau_batch_sudah_terkunci(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $batch = $this->makeBatch($this->puskesmasA, $admin);
        $patient1 = $this->makeProlanisPatient($this->puskesmasA);
        $patient2 = $this->makeProlanisPatient($this->puskesmasA);
        $batch->pasien()->create(['nama_snapshot' => $patient1->nama, 'external_patient_id' => $patient1->external_patient_id, 'urutan' => 1]);
        $batch->update(['status' => 'terkunci', 'dikunci_at' => now(), 'dikunci_oleh' => $admin->id]);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/pasien", [
            'external_patient_id' => $patient2->external_patient_id,
        ]);

        $response->assertStatus(422);
    }

    // ---- Hapus pasien ----

    public function test_hapus_pasien_dari_antrian(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $batch = $this->makeBatch($this->puskesmasA, $admin);
        $patient = $this->makeProlanisPatient($this->puskesmasA);
        $pasien = $batch->pasien()->create(['nama_snapshot' => $patient->nama, 'external_patient_id' => $patient->external_patient_id, 'urutan' => 1]);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/pengiriman-sampel/{$batch->id}/pasien/{$pasien->id}")->assertOk();

        $this->assertSame(0, $batch->pasien()->count());
    }

    // ---- Reorder ----

    public function test_reorder_urutan_meja_a_b_c(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $batch = $this->makeBatch($this->puskesmasA, $admin);
        $b = $batch->pasien()->create(['nama_snapshot' => 'Pasien B', 'urutan' => 1]);
        $c = $batch->pasien()->create(['nama_snapshot' => 'Pasien C', 'urutan' => 2]);
        $a = $batch->pasien()->create(['nama_snapshot' => 'Pasien A', 'urutan' => 3]);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/reorder", [
            'pasien_ids' => [$a->id, $b->id, $c->id],
        ]);

        $response->assertOk();
        $this->assertSame(1, $a->fresh()->urutan);
        $this->assertSame(2, $b->fresh()->urutan);
        $this->assertSame(3, $c->fresh()->urutan);
    }

    public function test_reorder_ditolak_kalau_daftar_id_tidak_cocok(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $batch = $this->makeBatch($this->puskesmasA, $admin);
        $a = $batch->pasien()->create(['nama_snapshot' => 'Pasien A', 'urutan' => 1]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/reorder", [
            'pasien_ids' => [$a->id, 99999],
        ])->assertStatus(422);
    }

    // ---- Kunci / Edit ----

    public function test_kunci_antrian_kosong_ditolak(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $batch = $this->makeBatch($this->puskesmasA, $admin);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/lock")->assertStatus(422);
    }

    public function test_kunci_lalu_edit_daftar_balik_ke_draft(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $batch = $this->makeBatch($this->puskesmasA, $admin);
        $batch->pasien()->create(['nama_snapshot' => 'Pasien A', 'urutan' => 1]);

        Sanctum::actingAs($admin);

        $lockResponse = $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/lock");
        $lockResponse->assertOk();
        $this->assertSame('terkunci', $batch->fresh()->status);

        $unlockResponse = $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/unlock");
        $unlockResponse->assertOk();
        $this->assertSame('draft', $batch->fresh()->status);
    }

    // ---- Batalkan ----

    public function test_batalkan_antrian_draft(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $batch = $this->makeBatch($this->puskesmasA, $admin);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/cancel")->assertOk();
        $this->assertSame('dibatalkan', $batch->fresh()->status);
    }

    // ---- Scoping ----

    public function test_admin_puskesmas_tidak_bisa_akses_antrian_puskesmas_lain(): void
    {
        $adminB = $this->makeUser('admin_puskesmas', $this->puskesmasB);
        $creatorA = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $batchA = $this->makeBatch($this->puskesmasA, $creatorA);

        Sanctum::actingAs($adminB);

        $this->getJson("/api/v1/pengiriman-sampel/{$batchA->id}")->assertStatus(403);
    }

    public function test_index_hanya_menampilkan_antrian_puskesmas_sendiri(): void
    {
        $adminA = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $batchA = $this->makeBatch($this->puskesmasA, $adminA);
        $creatorB = $this->makeUser('admin_puskesmas', $this->puskesmasB);
        $this->makeBatch($this->puskesmasB, $creatorB);

        Sanctum::actingAs($adminA);

        $response = $this->getJson('/api/v1/pengiriman-sampel');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertEquals([$batchA->id], $ids->all());
    }

    // ---- Kandidat pasien ----

    public function test_patient_candidates_hanya_pasien_prolanis_puskesmas_sendiri(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $prolanisA = $this->makeProlanisPatient($this->puskesmasA);
        $this->makeProlanisPatient($this->puskesmasA, ['is_prolanis' => false, 'jenis_prolanis' => null, 'nama' => 'Bukan Prolanis']);
        $this->makeProlanisPatient($this->puskesmasB);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/pengiriman-sampel/patient-candidates');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertEquals([$prolanisA->id], $ids->all());
    }

    public function test_kader_tidak_bisa_bikin_antrian(): void
    {
        $kader = $this->makeUser('kader', $this->puskesmasA);
        Sanctum::actingAs($kader);

        $this->postJson('/api/v1/pengiriman-sampel')->assertStatus(403);
    }
}
