<?php

namespace Tests\Feature\PengirimanSampel;

use App\Models\Kabupaten;
use App\Models\PengantarSampel;
use App\Models\PengirimanSampel;
use App\Models\PengirimanSampelLokasi;
use App\Models\Puskesmas;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase C modul "Kirim Data Prolanis ke Labkesda Sumenep" -- penugasan kurir, OTW, heartbeat GPS,
 * konfirmasi tiba dengan foto. Lihat docblock PengirimanSampelService untuk state-machine.
 */
class PengirimanSampelKurirControllerTest extends TestCase
{
    use RefreshDatabase;

    private Puskesmas $puskesmasA;

    private Puskesmas $puskesmasB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
        Storage::fake('s3');
        config(['produli.storage.visit_photos_disk' => 's3']);
        // confirmArrival() (Fase C) sekarang dispatch SendProlanisDeliveryToSilakesJob (Fase D)
        // -- Queue::fake() supaya test ini TIDAK benar-benar memanggil SiLAKES (network nyata),
        // job itu diuji sendiri terpisah, bukan tanggung jawab test kurir ini.
        Queue::fake();

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

    private function makeCourier(Puskesmas $puskesmas): PengantarSampel
    {
        static $n = 0;
        $n++;
        $user = User::factory()->create(['puskesmas_id' => $puskesmas->id, 'email' => "kurir{$n}@example.test"]);
        $user->assignRole('pengantar_sampel');

        return PengantarSampel::create(['user_id' => $user->id, 'puskesmas_id' => $puskesmas->id, 'status_aktif' => true, 'no_hp' => '0800']);
    }

    private function makeLockedBatch(Puskesmas $puskesmas, User $creator): PengirimanSampel
    {
        $batch = PengirimanSampel::create(['puskesmas_id' => $puskesmas->id, 'status' => 'draft', 'dibuat_oleh' => $creator->id]);
        $batch->pasien()->create(['nama_snapshot' => 'Pasien A', 'urutan' => 1]);
        $batch->update(['status' => 'terkunci', 'dikunci_at' => now(), 'dikunci_oleh' => $creator->id]);

        return $batch->fresh();
    }

    // ---- Tugaskan pengantar ----

    public function test_admin_puskesmas_menugaskan_pengantar_ke_batch_terkunci(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $batch = $this->makeLockedBatch($this->puskesmasA, $admin);
        $courier = $this->makeCourier($this->puskesmasA);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/assign-courier", [
            'pengantar_sampel_id' => $courier->id,
        ]);

        $response->assertOk();
        $this->assertSame('ditugaskan', $batch->fresh()->status);
        $this->assertSame($courier->id, $batch->fresh()->pengantar_sampel_id);
    }

    public function test_tugaskan_pengantar_ditolak_kalau_batch_masih_draft(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $batch = PengirimanSampel::create(['puskesmas_id' => $this->puskesmasA->id, 'status' => 'draft', 'dibuat_oleh' => $admin->id]);
        $courier = $this->makeCourier($this->puskesmasA);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/assign-courier", [
            'pengantar_sampel_id' => $courier->id,
        ])->assertStatus(422);
    }

    public function test_tugaskan_pengantar_dari_puskesmas_lain_ditolak(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $batch = $this->makeLockedBatch($this->puskesmasA, $admin);
        $courierB = $this->makeCourier($this->puskesmasB);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/assign-courier", [
            'pengantar_sampel_id' => $courierB->id,
        ])->assertStatus(422);
    }

    // ---- Mulai OTW ----

    public function test_kurir_mulai_otw(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $batch = $this->makeLockedBatch($this->puskesmasA, $admin);
        $courier = $this->makeCourier($this->puskesmasA);
        $batch->update(['status' => 'ditugaskan', 'pengantar_sampel_id' => $courier->id, 'ditugaskan_at' => now(), 'ditugaskan_oleh' => $admin->id]);

        Sanctum::actingAs($courier->user);

        $response = $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/start-otw");

        $response->assertOk();
        $this->assertSame('otw', $batch->fresh()->status);
        $this->assertNotNull($batch->fresh()->otw_at);
    }

    public function test_kurir_lain_tidak_bisa_mulai_otw_batch_orang_lain(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $batch = $this->makeLockedBatch($this->puskesmasA, $admin);
        $courier = $this->makeCourier($this->puskesmasA);
        $otherCourier = $this->makeCourier($this->puskesmasA);
        $batch->update(['status' => 'ditugaskan', 'pengantar_sampel_id' => $courier->id, 'ditugaskan_at' => now(), 'ditugaskan_oleh' => $admin->id]);

        Sanctum::actingAs($otherCourier->user);

        $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/start-otw")->assertStatus(403);
    }

    public function test_admin_puskesmas_tidak_bisa_mulai_otw_atas_nama_kurir(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $batch = $this->makeLockedBatch($this->puskesmasA, $admin);
        $courier = $this->makeCourier($this->puskesmasA);
        $batch->update(['status' => 'ditugaskan', 'pengantar_sampel_id' => $courier->id, 'ditugaskan_at' => now(), 'ditugaskan_oleh' => $admin->id]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/start-otw")->assertStatus(403);
    }

    // ---- Heartbeat GPS ----

    public function test_heartbeat_hanya_berlaku_selama_otw(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $batch = $this->makeLockedBatch($this->puskesmasA, $admin);
        $courier = $this->makeCourier($this->puskesmasA);
        $batch->update(['status' => 'ditugaskan', 'pengantar_sampel_id' => $courier->id, 'ditugaskan_at' => now(), 'ditugaskan_oleh' => $admin->id]);

        Sanctum::actingAs($courier->user);

        $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/heartbeat", [
            'latitude' => -7.0, 'longitude' => 113.8,
        ])->assertStatus(422);

        $batch->update(['status' => 'otw', 'otw_at' => now()]);

        $response = $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/heartbeat", [
            'latitude' => -7.0, 'longitude' => 113.8, 'accuracy' => 12.5,
        ]);
        $response->assertOk();

        $this->assertNotNull($batch->fresh()->lokasi);
        $this->assertEquals(-7.0, (float) $batch->fresh()->lokasi->latitude);
    }

    public function test_heartbeat_upsert_bukan_insert_berulang(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $batch = $this->makeLockedBatch($this->puskesmasA, $admin);
        $courier = $this->makeCourier($this->puskesmasA);
        $batch->update(['status' => 'otw', 'pengantar_sampel_id' => $courier->id, 'otw_at' => now()]);

        Sanctum::actingAs($courier->user);

        $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/heartbeat", ['latitude' => -7.0, 'longitude' => 113.8])->assertOk();
        $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/heartbeat", ['latitude' => -7.1, 'longitude' => 113.9])->assertOk();

        $this->assertSame(1, PengirimanSampelLokasi::where('pengiriman_sampel_id', $batch->id)->count());
        $this->assertEquals(-7.1, (float) $batch->fresh()->lokasi->latitude);
    }

    // ---- Konfirmasi tiba ----

    public function test_kurir_konfirmasi_tiba_dengan_foto(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $batch = $this->makeLockedBatch($this->puskesmasA, $admin);
        $courier = $this->makeCourier($this->puskesmasA);
        $batch->update(['status' => 'otw', 'pengantar_sampel_id' => $courier->id, 'otw_at' => now()]);
        $batch->lokasi()->create(['latitude' => -7.0, 'longitude' => 113.8, 'recorded_at' => now()]);

        Sanctum::actingAs($courier->user);

        $response = $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/confirm-arrival", [
            'photo' => UploadedFile::fake()->image('bukti.jpg'),
            'latitude' => -7.01,
            'longitude' => 113.85,
            'gps_accuracy_meters' => 8.0,
            'gps_captured_at' => now()->toIso8601String(),
        ]);

        $response->assertOk();
        $fresh = $batch->fresh();
        $this->assertSame('tiba_labkesda', $fresh->status);
        $this->assertNotNull($fresh->foto_bukti_path);
        $this->assertNull($fresh->lokasi()->first());
        Storage::disk('s3')->assertExists($fresh->foto_bukti_path);
    }

    public function test_konfirmasi_tiba_ditolak_kalau_belum_otw(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $batch = $this->makeLockedBatch($this->puskesmasA, $admin);
        $courier = $this->makeCourier($this->puskesmasA);
        $batch->update(['status' => 'ditugaskan', 'pengantar_sampel_id' => $courier->id, 'ditugaskan_at' => now()]);

        Sanctum::actingAs($courier->user);

        $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/confirm-arrival", [
            'photo' => UploadedFile::fake()->image('bukti.jpg'),
            'latitude' => -7.01,
            'longitude' => 113.85,
            'gps_captured_at' => now()->toIso8601String(),
        ])->assertStatus(422);
    }

    // ---- Daftar tugas kurir ----

    public function test_kurir_lihat_daftar_tugasnya_sendiri(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $courier = $this->makeCourier($this->puskesmasA);
        $batchMine = $this->makeLockedBatch($this->puskesmasA, $admin);
        $batchMine->update(['status' => 'otw', 'pengantar_sampel_id' => $courier->id, 'otw_at' => now()]);

        $otherCourier = $this->makeCourier($this->puskesmasA);
        $batchOther = $this->makeLockedBatch($this->puskesmasA, $admin);
        $batchOther->update(['status' => 'otw', 'pengantar_sampel_id' => $otherCourier->id, 'otw_at' => now()]);

        Sanctum::actingAs($courier->user);

        $response = $this->getJson('/api/v1/pengiriman-sampel/mine');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertEquals([$batchMine->id], $ids->all());
    }
}
