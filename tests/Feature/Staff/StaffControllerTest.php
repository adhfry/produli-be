<?php

namespace Tests\Feature\Staff;

use App\Mail\AccountActivationMail;
use App\Models\Kabupaten;
use App\Models\Puskesmas;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresi untuk POST /api/v1/staff (docs/planning/02 §7, §11) -- super_admin mendaftarkan
 * admin_puskesmas/pj_prolanis baru (puskesmas mana pun); admin_puskesmas cuma boleh
 * mendaftarkan pj_prolanis, dipaksa ke puskesmas miliknya sendiri. Pola find-or-create User by
 * email sama seperti kader.
 */
class StaffControllerTest extends TestCase
{
    use RefreshDatabase;

    private Puskesmas $puskesmas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $this->puskesmas = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-A', 'nama' => 'Puskesmas A']);
    }

    private function makeSuperAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }

    private function makeAdminPuskesmas(?Puskesmas $puskesmas = null): User
    {
        $user = User::factory()->create(['puskesmas_id' => ($puskesmas ?? $this->puskesmas)->id]);
        $user->assignRole('admin_puskesmas');

        return $user;
    }

    public function test_super_admin_mendaftarkan_admin_puskesmas_baru(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->makeSuperAdmin());

        $response = $this->postJson('/api/v1/staff', [
            'name' => 'Admin Baru',
            'email' => 'admin.baru@example.test',
            'no_hp' => '081234567890',
            'puskesmas_id' => $this->puskesmas->id,
            'role' => 'admin_puskesmas',
        ]);

        $response->assertCreated();
        $this->assertSame('success', $response->json('status'));

        $user = User::where('email', 'admin.baru@example.test')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->password);
        $this->assertTrue($user->hasRole('admin_puskesmas'));
        $this->assertSame($this->puskesmas->id, $user->puskesmas_id);
        $this->assertSame('081234567890', $user->no_hp);

        Mail::assertQueued(AccountActivationMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_super_admin_mendaftarkan_pj_prolanis_baru(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->makeSuperAdmin());

        $response = $this->postJson('/api/v1/staff', [
            'name' => 'PJ Baru',
            'email' => 'pj.baru@example.test',
            'no_hp' => '081234567891',
            'puskesmas_id' => $this->puskesmas->id,
            'role' => 'pj_prolanis',
        ]);

        $response->assertCreated();
        $user = User::where('email', 'pj.baru@example.test')->first();
        $this->assertTrue($user->hasRole('pj_prolanis'));
    }

    public function test_registrasi_staf_dengan_email_existing_tidak_kirim_email_lagi(): void
    {
        Mail::fake();
        $existingUser = User::factory()->create(['email' => 'sudah.ada@example.test']);
        Sanctum::actingAs($this->makeSuperAdmin());

        $response = $this->postJson('/api/v1/staff', [
            'name' => 'Nama Diabaikan',
            'email' => 'sudah.ada@example.test',
            'no_hp' => '081200000000',
            'puskesmas_id' => $this->puskesmas->id,
            'role' => 'pj_prolanis',
        ]);

        $response->assertCreated();
        $this->assertSame(1, User::where('email', 'sudah.ada@example.test')->count());
        $this->assertTrue($existingUser->fresh()->hasRole('pj_prolanis'));

        Mail::assertNothingQueued();
    }

    public function test_bukan_super_admin_atau_admin_puskesmas_ditolak(): void
    {
        $kaderUser = User::factory()->create(['puskesmas_id' => $this->puskesmas->id]);
        $kaderUser->assignRole('kader');

        Sanctum::actingAs($kaderUser);

        $response = $this->postJson('/api/v1/staff', [
            'name' => 'Staf Baru',
            'email' => 'gagal@example.test',
            'no_hp' => '081200000000',
            'puskesmas_id' => $this->puskesmas->id,
            'role' => 'pj_prolanis',
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_puskesmas_bisa_daftarkan_pj_prolanis_dipaksa_puskesmas_sendiri(): void
    {
        Mail::fake();
        $puskesmasLain = Puskesmas::create([
            'kabupaten_id' => $this->puskesmas->kabupaten_id,
            'kode_internal' => 'PKM-B',
            'nama' => 'Puskesmas B',
        ]);
        $admin = $this->makeAdminPuskesmas();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/staff', [
            'name' => 'PJ Baru',
            'email' => 'pj.oleh.admin@example.test',
            'no_hp' => '081200000000',
            // Selundupkan puskesmas_id BEDA dari milik admin -- harus diabaikan (defense in depth).
            'puskesmas_id' => $puskesmasLain->id,
            'role' => 'pj_prolanis',
        ]);

        $response->assertCreated();
        $user = User::where('email', 'pj.oleh.admin@example.test')->first();
        $this->assertTrue($user->hasRole('pj_prolanis'));
        $this->assertSame($this->puskesmas->id, $user->puskesmas_id);

        Mail::assertQueued(AccountActivationMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_admin_puskesmas_ditolak_daftarkan_sesama_admin_puskesmas(): void
    {
        Mail::fake();
        $admin = $this->makeAdminPuskesmas();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/staff', [
            'name' => 'Admin Baru',
            'email' => 'gagal.admin@example.test',
            'no_hp' => '081200000000',
            'role' => 'admin_puskesmas',
        ]);

        $response->assertStatus(422);
        $this->assertNull(User::where('email', 'gagal.admin@example.test')->first());
        Mail::assertNothingQueued();
    }

    public function test_super_admin_tanpa_puskesmas_id_ditolak_422(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        $response = $this->postJson('/api/v1/staff', [
            'name' => 'PJ Baru',
            'email' => 'gagal.superadmin@example.test',
            'no_hp' => '081200000000',
            'role' => 'pj_prolanis',
        ]);

        $response->assertStatus(422);
    }

    public function test_role_tidak_valid_ditolak_422(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        $response = $this->postJson('/api/v1/staff', [
            'name' => 'Admin Baru',
            'email' => 'gagal2@example.test',
            'no_hp' => '081200000000',
            'puskesmas_id' => $this->puskesmas->id,
            'role' => 'kader',
        ]);

        $response->assertStatus(422);
    }

    public function test_no_hp_wajib(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        $response = $this->postJson('/api/v1/staff', [
            'name' => 'Admin Baru',
            'email' => 'gagal3@example.test',
            'puskesmas_id' => $this->puskesmas->id,
            'role' => 'admin_puskesmas',
        ]);

        $response->assertStatus(422);
    }

    public function test_tanpa_login_ditolak_401(): void
    {
        $response = $this->postJson('/api/v1/staff', []);

        $response->assertStatus(401);
    }

    // ---- List (GET /api/v1/staff) ----

    public function test_super_admin_melihat_semua_staf(): void
    {
        $puskesmasLain = Puskesmas::create(['kabupaten_id' => $this->puskesmas->kabupaten_id, 'kode_internal' => 'PKM-B', 'nama' => 'Puskesmas B']);
        $adminA = $this->makeAdminPuskesmas();
        $adminB = $this->makeAdminPuskesmas($puskesmasLain);
        $superAdmin = $this->makeSuperAdmin();

        Sanctum::actingAs($superAdmin);

        $response = $this->getJson('/api/v1/staff');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        // StaffService::scopedQuery() menyertakan role super_admin (bukan cuma admin_puskesmas/
        // pj_prolanis) -- super_admin yang login pun ikut muncul di daftarnya sendiri.
        $this->assertEqualsCanonicalizing([$adminA->id, $adminB->id, $superAdmin->id], $ids->all());
    }

    public function test_admin_puskesmas_hanya_melihat_staf_puskesmas_sendiri(): void
    {
        $puskesmasLain = Puskesmas::create(['kabupaten_id' => $this->puskesmas->kabupaten_id, 'kode_internal' => 'PKM-B', 'nama' => 'Puskesmas B']);
        $admin = $this->makeAdminPuskesmas();
        $pjSamaPuskesmas = User::factory()->create(['puskesmas_id' => $this->puskesmas->id]);
        $pjSamaPuskesmas->assignRole('pj_prolanis');
        $this->makeAdminPuskesmas($puskesmasLain);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/staff');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertEqualsCanonicalizing([$admin->id, $pjSamaPuskesmas->id], $ids->all());
    }

    public function test_list_staf_tidak_menyertakan_kader(): void
    {
        $admin = $this->makeAdminPuskesmas();
        $kaderUser = User::factory()->create(['puskesmas_id' => $this->puskesmas->id]);
        $kaderUser->assignRole('kader');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/staff');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertEquals([$admin->id], $ids->all());
    }

    public function test_kader_ditolak_akses_list_staf(): void
    {
        $kaderUser = User::factory()->create(['puskesmas_id' => $this->puskesmas->id]);
        $kaderUser->assignRole('kader');

        Sanctum::actingAs($kaderUser);

        $this->getJson('/api/v1/staff')->assertStatus(403);
    }

    public function test_list_staf_tanpa_login_ditolak_401(): void
    {
        $this->getJson('/api/v1/staff')->assertStatus(401);
    }
}
