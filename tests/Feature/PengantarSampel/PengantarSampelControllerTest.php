<?php

namespace Tests\Feature\PengantarSampel;

use App\Mail\AccountActivationMail;
use App\Mail\AdminPasswordResetMail;
use App\Models\Kabupaten;
use App\Models\PengantarSampel;
use App\Models\Puskesmas;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase A modul Kirim Data Prolanis ke Labkesda -- mirror persis TenagaKesehatanControllerTest
 * (lihat docblock di sana), dipangkas: role ini belum punya self-service /app profile (Fase C),
 * dan delete() belum menolak berdasarkan riwayat pengiriman sampel (tabelnya baru ada Fase C).
 */
class PengantarSampelControllerTest extends TestCase
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

    private function makePengantarSampel(Puskesmas $puskesmas): PengantarSampel
    {
        static $n = 0;
        $n++;
        $user = User::factory()->create(['puskesmas_id' => $puskesmas->id, 'email' => "ps{$n}@example.test"]);
        $user->assignRole('pengantar_sampel');

        return PengantarSampel::create(['user_id' => $user->id, 'puskesmas_id' => $puskesmas->id, 'status_aktif' => true, 'no_hp' => '0800']);
    }

    // ---- Registrasi ----

    public function test_admin_puskesmas_mendaftarkan_pengantar_sampel_baru(): void
    {
        Mail::fake();
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/pengantar-sampel', [
            'name' => 'Kurir Baru',
            'email' => 'kurir.baru@example.test',
            'no_hp' => '081234567890',
        ]);

        $response->assertCreated();
        $user = User::where('email', 'kurir.baru@example.test')->first();
        $this->assertTrue($user->hasRole('pengantar_sampel'));

        Mail::assertQueued(AccountActivationMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_kader_tidak_bisa_mendaftarkan_pengantar_sampel(): void
    {
        $kader = $this->makeUser('kader', $this->puskesmasA);
        Sanctum::actingAs($kader);

        $this->postJson('/api/v1/pengantar-sampel', [
            'name' => 'Kurir Baru',
            'email' => 'kurir.baru@example.test',
            'no_hp' => '081234567890',
        ])->assertStatus(403);
    }

    // ---- List ----

    public function test_admin_puskesmas_hanya_melihat_pengantar_sampel_puskesmas_sendiri(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $psA = $this->makePengantarSampel($this->puskesmasA);
        $this->makePengantarSampel($this->puskesmasB);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/pengantar-sampel');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertEquals([$psA->id], $ids->all());
    }

    // ---- Update ----

    public function test_pj_prolanis_bisa_update_data_pengantar_sampel(): void
    {
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $ps = $this->makePengantarSampel($this->puskesmasA);

        Sanctum::actingAs($pj);

        $response = $this->patchJson("/api/v1/pengantar-sampel/{$ps->id}", [
            'name' => 'Nama Baru',
            'no_hp' => '081211112222',
        ]);

        $response->assertOk();
        $ps->refresh();
        $this->assertSame('081211112222', $ps->no_hp);
        $this->assertSame('Nama Baru', $ps->user->name);
    }

    public function test_update_pengantar_sampel_ditolak_beda_puskesmas(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $psB = $this->makePengantarSampel($this->puskesmasB);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/pengantar-sampel/{$psB->id}", ['name' => 'Coba Ubah'])->assertStatus(403);
    }

    // ---- Status aktif ----

    public function test_admin_puskesmas_bisa_nonaktifkan_pengantar_sampel(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $ps = $this->makePengantarSampel($this->puskesmasA);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/v1/pengantar-sampel/{$ps->id}/status", ['status_aktif' => false]);

        $response->assertOk();
        $this->assertFalse($ps->fresh()->status_aktif);
    }

    // ---- Delete ----

    public function test_admin_puskesmas_bisa_hapus_pengantar_sampel(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $ps = $this->makePengantarSampel($this->puskesmasA);
        $userId = $ps->user_id;

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/pengantar-sampel/{$ps->id}")->assertOk();

        $this->assertNull(PengantarSampel::find($ps->id));
        $this->assertNull(User::find($userId));
    }

    // ---- Reset password ----

    public function test_super_admin_bisa_reset_password_pengantar_sampel(): void
    {
        Mail::fake();
        $superAdmin = $this->makeUser('super_admin');
        $ps = $this->makePengantarSampel($this->puskesmasA);
        $oldHash = $ps->user->password;

        Sanctum::actingAs($superAdmin);

        $response = $this->postJson("/api/v1/pengantar-sampel/{$ps->id}/reset-password");

        $response->assertOk();
        $ps->refresh();
        $this->assertNotSame($oldHash, $ps->user->password);
        $this->assertTrue($ps->user->must_change_password);
        Mail::assertQueued(AdminPasswordResetMail::class, fn ($mail) => $mail->hasTo($ps->user->email));
    }

    public function test_admin_puskesmas_tidak_bisa_reset_password_pengantar_sampel(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $ps = $this->makePengantarSampel($this->puskesmasA);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/pengantar-sampel/{$ps->id}/reset-password")->assertStatus(403);
    }
}
