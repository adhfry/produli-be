<?php

namespace Tests\Feature\TenagaKesehatan;

use App\Mail\AccountActivationMail;
use App\Mail\AdminPasswordResetMail;
use App\Models\Kabupaten;
use App\Models\PatientsCache;
use App\Models\Puskesmas;
use App\Models\TenagaKesehatan;
use App\Models\User;
use App\Models\VisitAssignment;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresi untuk registrasi/list tenaga_kesehatan (mirror KaderControllerTest) plus update/
 * delete/reset-password baru (revisi Bu Kadis) -- endpoint ini sebelumnya belum punya test
 * regresi sama sekali (cuma index/store/setStatus), test lama untuk itu ikut ditambahkan di sini
 * supaya tetap ter-cover bersamaan.
 */
class TenagaKesehatanControllerTest extends TestCase
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

    private function makeTenagaKesehatan(Puskesmas $puskesmas): TenagaKesehatan
    {
        static $n = 0;
        $n++;
        $user = User::factory()->create(['puskesmas_id' => $puskesmas->id, 'email' => "tk{$n}@example.test"]);
        $user->assignRole('tenaga_kesehatan');

        return TenagaKesehatan::create(['user_id' => $user->id, 'puskesmas_id' => $puskesmas->id, 'status_aktif' => true, 'no_hp' => '0800']);
    }

    // ---- Registrasi ----

    public function test_pj_prolanis_mendaftarkan_tenaga_kesehatan_baru(): void
    {
        Mail::fake();
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        Sanctum::actingAs($pj);

        $response = $this->postJson('/api/v1/tenaga-kesehatan', [
            'name' => 'TK Baru',
            'email' => 'tk.baru@example.test',
            'no_hp' => '081234567890',
        ]);

        $response->assertCreated();
        $user = User::where('email', 'tk.baru@example.test')->first();
        $this->assertTrue($user->hasRole('tenaga_kesehatan'));

        Mail::assertQueued(AccountActivationMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    // ---- List ----

    public function test_admin_puskesmas_hanya_melihat_tenaga_kesehatan_puskesmas_sendiri(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $tkA = $this->makeTenagaKesehatan($this->puskesmasA);
        $this->makeTenagaKesehatan($this->puskesmasB);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/tenaga-kesehatan');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertEquals([$tkA->id], $ids->all());
    }

    // ---- Update ----

    public function test_admin_puskesmas_bisa_update_data_tenaga_kesehatan(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $tk = $this->makeTenagaKesehatan($this->puskesmasA);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/v1/tenaga-kesehatan/{$tk->id}", [
            'name' => 'Nama Baru',
            'no_hp' => '081211112222',
        ]);

        $response->assertOk();
        $tk->refresh();
        $this->assertSame('081211112222', $tk->no_hp);
        $this->assertSame('Nama Baru', $tk->user->name);
    }

    public function test_update_tenaga_kesehatan_ditolak_beda_puskesmas(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $tkB = $this->makeTenagaKesehatan($this->puskesmasB);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/tenaga-kesehatan/{$tkB->id}", ['name' => 'Coba Ubah'])->assertStatus(403);
    }

    // ---- Delete ----

    public function test_admin_puskesmas_bisa_hapus_tenaga_kesehatan_tanpa_riwayat(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $tk = $this->makeTenagaKesehatan($this->puskesmasA);
        $userId = $tk->user_id;

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/tenaga-kesehatan/{$tk->id}")->assertOk();

        $this->assertNull(TenagaKesehatan::find($tk->id));
        $this->assertNull(User::find($userId));
    }

    public function test_hapus_tenaga_kesehatan_dengan_riwayat_penugasan_ditolak_422(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $tk = $this->makeTenagaKesehatan($this->puskesmasA);

        $patient = PatientsCache::create([
            'external_patient_id' => 999101,
            'nik_hash' => 'HASH-TK-DEL-1',
            'nama' => 'Pasien Uji TK',
            'puskesmas_id' => $this->puskesmasA->id,
            'wilayah_status' => 'resolved',
        ]);
        VisitAssignment::create([
            'patient_id' => $patient->id,
            'tenaga_kesehatan_id' => $tk->id,
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
            'scheduled_date' => now()->addDay(),
            'status' => 'pending',
            'priority' => 'ringan',
            'assignment_method' => 'wilayah_resolved',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->deleteJson("/api/v1/tenaga-kesehatan/{$tk->id}");

        $response->assertStatus(422);
        $this->assertNotNull(TenagaKesehatan::find($tk->id));
    }

    // ---- Reset password ----

    public function test_super_admin_bisa_reset_password_tenaga_kesehatan(): void
    {
        Mail::fake();
        $superAdmin = $this->makeUser('super_admin');
        $tk = $this->makeTenagaKesehatan($this->puskesmasA);
        $oldHash = $tk->user->password;

        Sanctum::actingAs($superAdmin);

        $response = $this->postJson("/api/v1/tenaga-kesehatan/{$tk->id}/reset-password");

        $response->assertOk();
        $tk->refresh();
        $this->assertNotSame($oldHash, $tk->user->password);
        $this->assertTrue($tk->user->must_change_password);
        Mail::assertQueued(AdminPasswordResetMail::class, fn ($mail) => $mail->hasTo($tk->user->email));
    }

    public function test_admin_puskesmas_tidak_bisa_reset_password_tenaga_kesehatan(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $tk = $this->makeTenagaKesehatan($this->puskesmasA);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/tenaga-kesehatan/{$tk->id}/reset-password")->assertStatus(403);
    }

    // ---- Self-service profil (revisi Bu Kadis PMO, mode /app) -- mirror KaderControllerTest ----

    public function test_tenaga_kesehatan_bisa_lihat_profil_sendiri(): void
    {
        $tk = $this->makeTenagaKesehatan($this->puskesmasA);

        Sanctum::actingAs($tk->user);

        $response = $this->getJson('/api/v1/tenaga-kesehatan/profile');

        $response->assertOk();
        $this->assertSame($tk->id, $response->json('data.id'));
        $this->assertSame($this->puskesmasA->id, $response->json('data.puskesmas.id'));
    }

    public function test_tenaga_kesehatan_bisa_update_profil_sendiri(): void
    {
        $tk = $this->makeTenagaKesehatan($this->puskesmasA);

        Sanctum::actingAs($tk->user);

        $response = $this->patchJson('/api/v1/tenaga-kesehatan/profile', [
            'no_wa' => '081298765432',
            'alamat' => 'Jl. Contoh No. 9',
            'gender' => 'L',
        ]);

        $response->assertOk();
        $tk->refresh();
        $this->assertSame('081298765432', $tk->no_wa);
        $this->assertSame('Jl. Contoh No. 9', $tk->alamat);
        $this->assertSame('L', $tk->gender);
    }

    public function test_tenaga_kesehatan_tidak_bisa_update_profil_via_puskesmas_id(): void
    {
        $tk = $this->makeTenagaKesehatan($this->puskesmasA);

        Sanctum::actingAs($tk->user);

        $this->patchJson('/api/v1/tenaga-kesehatan/profile', ['puskesmas_id' => $this->puskesmasB->id])->assertOk();

        $this->assertSame($this->puskesmasA->id, $tk->fresh()->puskesmas_id);
    }

    public function test_admin_puskesmas_tidak_punya_profil_tenaga_kesehatan_ditolak_422(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/tenaga-kesehatan/profile')->assertStatus(422);
    }

    public function test_lihat_profil_tenaga_kesehatan_tanpa_login_ditolak_401(): void
    {
        $this->getJson('/api/v1/tenaga-kesehatan/profile')->assertStatus(401);
    }
}
