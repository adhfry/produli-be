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

    // ---- Update ----

    public function test_super_admin_bisa_update_staf(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $admin = $this->makeAdminPuskesmas();

        Sanctum::actingAs($superAdmin);

        $response = $this->patchJson("/api/v1/staff/{$admin->id}", [
            'name' => 'Nama Baru',
            'no_hp' => '081200001111',
        ]);

        $response->assertOk();
        $this->assertSame('Nama Baru', $response->json('data.name'));
        $this->assertSame('081200001111', $admin->fresh()->no_hp);
    }

    public function test_admin_puskesmas_ditolak_update_sesama_admin_puskesmas(): void
    {
        $admin = $this->makeAdminPuskesmas();
        $adminLain = $this->makeAdminPuskesmas();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/staff/{$adminLain->id}", ['name' => 'Coba Ubah'])->assertStatus(422);
    }

    public function test_admin_puskesmas_bisa_update_pj_prolanis_puskesmas_sendiri(): void
    {
        $admin = $this->makeAdminPuskesmas();
        $pj = User::factory()->create(['puskesmas_id' => $this->puskesmas->id]);
        $pj->assignRole('pj_prolanis');

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/staff/{$pj->id}", ['name' => 'PJ Diubah'])->assertOk();
        $this->assertSame('PJ Diubah', $pj->fresh()->name);
    }

    // ---- Delete ----

    public function test_super_admin_bisa_hapus_admin_puskesmas(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $admin = $this->makeAdminPuskesmas();

        Sanctum::actingAs($superAdmin);

        $this->deleteJson("/api/v1/staff/{$admin->id}")->assertOk();
        $this->assertNull(User::find($admin->id));
    }

    public function test_tidak_bisa_hapus_diri_sendiri(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        Sanctum::actingAs($superAdmin);

        $this->deleteJson("/api/v1/staff/{$superAdmin->id}")->assertStatus(422);
        $this->assertNotNull(User::find($superAdmin->id));
    }

    public function test_super_admin_bisa_hapus_super_admin_lain_kalau_bukan_yang_terakhir(): void
    {
        $superAdminA = $this->makeSuperAdmin();
        $superAdminB = $this->makeSuperAdmin();

        Sanctum::actingAs($superAdminA);

        $this->deleteJson("/api/v1/staff/{$superAdminB->id}")->assertOk();
        $this->assertNull(User::find($superAdminB->id));
        $this->assertSame(1, User::role('super_admin')->count());
    }

    public function test_hapus_staf_dual_role_kader_hanya_lepas_role_staf(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $dualUser = $this->makeAdminPuskesmas();
        $dualUser->assignRole('kader');
        \App\Models\Kader::create(['user_id' => $dualUser->id, 'puskesmas_id' => $this->puskesmas->id, 'status_aktif' => true, 'no_hp' => '0800']);

        Sanctum::actingAs($superAdmin);

        $this->deleteJson("/api/v1/staff/{$dualUser->id}")->assertOk();

        $dualUser->refresh();
        $this->assertNotNull($dualUser);
        $this->assertFalse($dualUser->hasRole('admin_puskesmas'));
        $this->assertTrue($dualUser->hasRole('kader'));
    }

    public function test_admin_puskesmas_ditolak_hapus_staf_beda_puskesmas(): void
    {
        $puskesmasLain = Puskesmas::create(['kabupaten_id' => $this->puskesmas->kabupaten_id, 'kode_internal' => 'PKM-C', 'nama' => 'Puskesmas C']);
        $admin = $this->makeAdminPuskesmas();
        $pjLain = User::factory()->create(['puskesmas_id' => $puskesmasLain->id]);
        $pjLain->assignRole('pj_prolanis');

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/staff/{$pjLain->id}")->assertStatus(422);
        $this->assertNotNull(User::find($pjLain->id));
    }

    // ---- Reset password ----

    public function test_super_admin_bisa_reset_password_staf(): void
    {
        Mail::fake();
        $superAdmin = $this->makeSuperAdmin();
        $admin = $this->makeAdminPuskesmas();
        $oldHash = $admin->password;

        Sanctum::actingAs($superAdmin);

        $response = $this->postJson("/api/v1/staff/{$admin->id}/reset-password");

        $response->assertOk();
        $admin->refresh();
        $this->assertNotSame($oldHash, $admin->password);
        $this->assertTrue($admin->must_change_password);
        Mail::assertQueued(\App\Mail\AdminPasswordResetMail::class, fn ($mail) => $mail->hasTo($admin->email));
    }

    public function test_admin_puskesmas_tidak_bisa_reset_password_staf(): void
    {
        $admin = $this->makeAdminPuskesmas();
        $pj = User::factory()->create(['puskesmas_id' => $this->puskesmas->id]);
        $pj->assignRole('pj_prolanis');

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/staff/{$pj->id}/reset-password")->assertStatus(403);
    }
}
