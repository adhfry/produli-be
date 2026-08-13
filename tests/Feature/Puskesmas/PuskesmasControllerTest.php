<?php

namespace Tests\Feature\Puskesmas;

use App\Models\Kabupaten;
use App\Models\Puskesmas;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresi untuk Data Instansi / Puskesmas (docs/planning/02 §15) -- GET list/detail semua
 * role login tanpa scope, PATCH cuma super_admin (bebas) atau admin_puskesmas (puskesmasnya
 * sendiri), field yang boleh diubah cuma alamat/no_telp/no_wa/latitude/longitude/deskripsi.
 * Tidak ada POST/DELETE (dites lewat routeless check di bawah).
 */
class PuskesmasControllerTest extends TestCase
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

    // ---- GET (list & detail) ----

    public function test_semua_role_bisa_lihat_daftar_puskesmas(): void
    {
        foreach (['super_admin', 'admin_puskesmas', 'pj_prolanis', 'kader'] as $role) {
            Sanctum::actingAs($this->makeUser($role, $this->puskesmasA));

            $response = $this->getJson('/api/v1/puskesmas');

            $response->assertOk();
            $this->assertCount(2, $response->json('data.items'));
        }
    }

    public function test_semua_role_bisa_lihat_detail_puskesmas(): void
    {
        Sanctum::actingAs($this->makeUser('kader', $this->puskesmasA));

        $response = $this->getJson("/api/v1/puskesmas/{$this->puskesmasB->id}");

        $response->assertOk();
        $this->assertSame('Puskesmas B', $response->json('data.nama'));
    }

    public function test_daftar_puskesmas_tanpa_login_ditolak_401(): void
    {
        $this->getJson('/api/v1/puskesmas')->assertStatus(401);
    }

    // ---- PATCH ----

    public function test_super_admin_bisa_update_puskesmas_mana_pun(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        Sanctum::actingAs($superAdmin);

        $response = $this->patchJson("/api/v1/puskesmas/{$this->puskesmasB->id}", [
            'alamat' => 'Jl. Raya No. 1',
            'no_telp' => '031-1234567',
            'no_wa' => '081234567890',
            'latitude' => -7.0123,
            'longitude' => 113.8456,
            'deskripsi' => 'Puskesmas rawat inap.',
        ]);

        $response->assertOk();
        $updated = $this->puskesmasB->fresh();
        $this->assertSame('Jl. Raya No. 1', $updated->alamat);
        $this->assertSame('031-1234567', $updated->no_telp);
        $this->assertSame('081234567890', $updated->no_wa);
        $this->assertEquals(-7.0123, (float) $updated->latitude);
        $this->assertEquals(113.8456, (float) $updated->longitude);
        $this->assertSame('Puskesmas rawat inap.', $updated->deskripsi);
    }

    public function test_admin_puskesmas_bisa_update_puskesmasnya_sendiri(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/v1/puskesmas/{$this->puskesmasA->id}", [
            'no_telp' => '031-7654321',
        ]);

        $response->assertOk();
        $this->assertSame('031-7654321', $this->puskesmasA->fresh()->no_telp);
    }

    public function test_admin_puskesmas_ditolak_update_puskesmas_lain(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/v1/puskesmas/{$this->puskesmasB->id}", [
            'no_telp' => '031-0000000',
        ]);

        $response->assertStatus(403);
        $this->assertNull($this->puskesmasB->fresh()->no_telp);
    }

    public function test_pj_prolanis_dan_kader_ditolak_update(): void
    {
        foreach (['pj_prolanis', 'kader'] as $role) {
            Sanctum::actingAs($this->makeUser($role, $this->puskesmasA));

            $response = $this->patchJson("/api/v1/puskesmas/{$this->puskesmasA->id}", [
                'no_telp' => '031-9999999',
            ]);

            $response->assertStatus(403);
        }
    }

    public function test_nama_dan_kode_internal_tidak_bisa_diubah_lewat_endpoint(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        Sanctum::actingAs($superAdmin);

        $response = $this->patchJson("/api/v1/puskesmas/{$this->puskesmasA->id}", [
            'nama' => 'Nama Palsu',
            'kode_internal' => 'PKM-HACK',
            'alamat' => 'Alamat baru',
        ]);

        $response->assertOk();
        $updated = $this->puskesmasA->fresh();
        $this->assertSame('Puskesmas A', $updated->nama);
        $this->assertSame('PKM-A', $updated->kode_internal);
        $this->assertSame('Alamat baru', $updated->alamat);
    }

    public function test_update_puskesmas_tanpa_login_ditolak_401(): void
    {
        $this->patchJson("/api/v1/puskesmas/{$this->puskesmasA->id}", [])->assertStatus(401);
    }

    // ---- POST geocode-all (cari-otomatis koordinat via Nominatim, permintaan Bu Kadis) ----

    private function fakeNominatim(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::sequence()
                ->push([['lat' => '-7.0111', 'lon' => '113.8222']], 200)
                ->push([['lat' => '-7.0333', 'lon' => '113.8444']], 200),
        ]);
    }

    public function test_super_admin_bisa_jalankan_geocode_all(): void
    {
        $this->fakeNominatim();
        Sanctum::actingAs($this->makeUser('super_admin'));

        $response = $this->postJson('/api/v1/puskesmas/geocode-all');

        $response->assertOk();
        $this->assertSame(2, $response->json('data.total'));
        $this->assertSame(2, $response->json('data.updated'));
        $this->assertSame(0, $response->json('data.failed'));
        $this->assertEqualsWithDelta(-7.0111, (float) $this->puskesmasA->fresh()->latitude, 0.0001);
        $this->assertEqualsWithDelta(113.8222, (float) $this->puskesmasA->fresh()->longitude, 0.0001);
    }

    public function test_admin_puskesmas_ditolak_geocode_all(): void
    {
        Sanctum::actingAs($this->makeUser('admin_puskesmas', $this->puskesmasA));

        $response = $this->postJson('/api/v1/puskesmas/geocode-all');

        $response->assertStatus(403);
    }

    public function test_geocode_all_default_lewati_puskesmas_yang_sudah_punya_koordinat(): void
    {
        $this->puskesmasA->update(['latitude' => -7.5, 'longitude' => 113.5]);
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([['lat' => '-7.0111', 'lon' => '113.8222']], 200),
        ]);
        Sanctum::actingAs($this->makeUser('super_admin'));

        $response = $this->postJson('/api/v1/puskesmas/geocode-all');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.skipped'));
        $this->assertSame(1, $response->json('data.updated'));
        // Puskesmas A TIDAK disentuh -- koordinat lama tetap seperti semula.
        $this->assertEqualsWithDelta(-7.5, (float) $this->puskesmasA->fresh()->latitude, 0.0001);
    }

    public function test_geocode_all_overwrite_existing_timpa_yang_sudah_punya_koordinat(): void
    {
        $this->puskesmasA->update(['latitude' => -7.5, 'longitude' => 113.5]);
        $this->fakeNominatim();
        Sanctum::actingAs($this->makeUser('super_admin'));

        $response = $this->postJson('/api/v1/puskesmas/geocode-all', ['overwrite_existing' => true]);

        $response->assertOk();
        $this->assertSame(0, $response->json('data.skipped'));
        $this->assertSame(2, $response->json('data.updated'));
    }

    public function test_geocode_all_puskesmas_tidak_ditemukan_ditandai_gagal_bukan_crash(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([], 200),
        ]);
        Sanctum::actingAs($this->makeUser('super_admin'));

        $response = $this->postJson('/api/v1/puskesmas/geocode-all');

        $response->assertOk();
        $this->assertSame(2, $response->json('data.failed'));
        $this->assertSame(0, $response->json('data.updated'));
        $this->assertNull($this->puskesmasA->fresh()->latitude);
    }

    public function test_geocode_all_tanpa_login_ditolak_401(): void
    {
        $this->postJson('/api/v1/puskesmas/geocode-all')->assertStatus(401);
    }
}
