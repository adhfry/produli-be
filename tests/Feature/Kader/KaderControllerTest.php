<?php

namespace Tests\Feature\Kader;

use App\Mail\AccountActivationMail;
use App\Models\Kabupaten;
use App\Models\Kader;
use App\Models\Puskesmas;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresi untuk registrasi kader oleh PJ/admin_puskesmas/super_admin, list kader ter-scope
 * puskesmas, dan self-service update profil kader sendiri (docs/planning/02 §7).
 */
class KaderControllerTest extends TestCase
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

    // ---- Registrasi ----

    public function test_pj_prolanis_mendaftarkan_kader_baru_dengan_user_baru(): void
    {
        Mail::fake();
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        Sanctum::actingAs($pj);

        $response = $this->postJson('/api/v1/kader', [
            'name' => 'Kader Baru',
            'email' => 'kader.baru@example.test',
            'no_hp' => '081234567890',
        ]);

        $response->assertCreated();
        $this->assertSame('success', $response->json('status'));

        $user = User::where('email', 'kader.baru@example.test')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->password);
        $this->assertTrue($user->hasRole('kader'));
        // users.puskesmas_id ikut terisi (bukan cuma kader.puskesmas_id) -- dipakai scoping umum
        // (mis. resend aktivasi) di luar konteks kader-nya sendiri.
        $this->assertSame($this->puskesmasA->id, $user->puskesmas_id);

        $kader = Kader::where('user_id', $user->id)->first();
        $this->assertNotNull($kader);
        $this->assertSame($this->puskesmasA->id, $kader->puskesmas_id);
        $this->assertSame($pj->id, $kader->pj_id);
        $this->assertSame('081234567890', $kader->no_hp);

        // Retrofit alur aktivasi akun (docs/planning/02) -- User baru harus dikirimi email.
        Mail::assertQueued(AccountActivationMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_registrasi_pakai_email_user_yang_sudah_ada_tanpa_profil_kader(): void
    {
        Mail::fake();
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $existingUser = User::factory()->create(['email' => 'sudah.ada@example.test']);

        Sanctum::actingAs($pj);

        $response = $this->postJson('/api/v1/kader', [
            'name' => 'Nama Diabaikan',
            'email' => 'sudah.ada@example.test',
            'no_hp' => '081200000000',
        ]);

        $response->assertCreated();
        $this->assertSame(1, User::where('email', 'sudah.ada@example.test')->count());

        $kader = Kader::where('user_id', $existingUser->id)->first();
        $this->assertNotNull($kader);

        // User existing (sudah terdaftar sebelumnya) -- TIDAK dikirimi email aktivasi lagi.
        Mail::assertNothingQueued();
    }

    public function test_registrasi_ditolak_kalau_no_hp_kosong(): void
    {
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        Sanctum::actingAs($pj);

        $response = $this->postJson('/api/v1/kader', [
            'name' => 'Kader Baru',
            'email' => 'kader.tanpa.hp@example.test',
        ]);

        $response->assertStatus(422);
    }

    public function test_registrasi_ditolak_kalau_email_sudah_jadi_kader(): void
    {
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $kaderUser = $this->makeUser('kader', $this->puskesmasA);
        Kader::create(['user_id' => $kaderUser->id, 'puskesmas_id' => $this->puskesmasA->id, 'status_aktif' => true, 'no_hp' => '0800']);

        Sanctum::actingAs($pj);

        $response = $this->postJson('/api/v1/kader', [
            'name' => 'Duplikat',
            'email' => $kaderUser->email,
            'no_hp' => '081200000000',
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, Kader::where('user_id', $kaderUser->id)->count());
    }

    public function test_admin_puskesmas_bisa_assign_pj_id_spesifik(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/kader', [
            'name' => 'Kader Baru',
            'email' => 'kader.assigned@example.test',
            'no_hp' => '081200000000',
            'pj_id' => $pj->id,
        ]);

        $response->assertCreated();
        $kader = Kader::where('user_id', User::where('email', 'kader.assigned@example.test')->first()->id)->first();
        $this->assertSame($pj->id, $kader->pj_id);
    }

    public function test_registrasi_ditolak_pj_id_bukan_pj_prolanis(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $bukanPj = $this->makeUser('kader', $this->puskesmasA);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/kader', [
            'name' => 'Kader Baru',
            'email' => 'kader.gagal@example.test',
            'no_hp' => '081200000000',
            'pj_id' => $bukanPj->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_registrasi_ditolak_pj_id_beda_puskesmas(): void
    {
        // Defense in depth: PJ harus sepuskesmas dengan kader yang didaftarkan, meski
        // pj_id-nya valid pj_prolanis sungguhan (cuma beda puskesmas).
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $pjBeda = $this->makeUser('pj_prolanis', $this->puskesmasB);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/kader', [
            'name' => 'Kader Baru',
            'email' => 'kader.pj.beda@example.test',
            'no_hp' => '081200000000',
            'pj_id' => $pjBeda->id,
        ]);

        $response->assertStatus(422);
        $this->assertNull(User::where('email', 'kader.pj.beda@example.test')->first());
    }

    public function test_super_admin_wajib_isi_puskesmas_id(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/v1/kader', [
            'name' => 'Kader Baru',
            'email' => 'kader.superadmin@example.test',
            'no_hp' => '081200000000',
        ]);

        $response->assertStatus(422);
    }

    public function test_puskesmas_id_dari_input_diabaikan_untuk_admin_puskesmas(): void
    {
        // Defense in depth: admin_puskesmas A tidak boleh menyelundupkan puskesmas_id B.
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/kader', [
            'name' => 'Kader Baru',
            'email' => 'kader.selundup@example.test',
            'no_hp' => '081200000000',
            'puskesmas_id' => $this->puskesmasB->id,
        ]);

        $response->assertCreated();
        $kader = Kader::where('user_id', User::where('email', 'kader.selundup@example.test')->first()->id)->first();
        $this->assertSame($this->puskesmasA->id, $kader->puskesmas_id);
    }

    public function test_kader_murni_tidak_bisa_mendaftarkan_kader_baru(): void
    {
        $kaderUser = $this->makeUser('kader', $this->puskesmasA);
        Kader::create(['user_id' => $kaderUser->id, 'puskesmas_id' => $this->puskesmasA->id, 'status_aktif' => true, 'no_hp' => '0800']);

        Sanctum::actingAs($kaderUser);

        $response = $this->postJson('/api/v1/kader', [
            'name' => 'Kader Baru',
            'email' => 'kader.ditolak@example.test',
            'no_hp' => '081200000000',
        ]);

        $response->assertStatus(403);
    }

    // ---- PJ options (dropdown registrasi kader) ----

    public function test_admin_puskesmas_lihat_pj_options_puskesmas_sendiri_saja(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $pjA = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $this->makeUser('pj_prolanis', $this->puskesmasB);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/kader/pj-options');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertEquals([$pjA->id], $ids->all());
    }

    public function test_super_admin_bisa_filter_pj_options_by_puskesmas_id(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $pjA = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $this->makeUser('pj_prolanis', $this->puskesmasB);

        Sanctum::actingAs($superAdmin);

        $response = $this->getJson('/api/v1/kader/pj-options?puskesmas_id='.$this->puskesmasA->id);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertEquals([$pjA->id], $ids->all());
    }

    public function test_super_admin_tanpa_filter_melihat_semua_pj_options(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $this->makeUser('pj_prolanis', $this->puskesmasA);
        $this->makeUser('pj_prolanis', $this->puskesmasB);

        Sanctum::actingAs($superAdmin);

        $response = $this->getJson('/api/v1/kader/pj-options');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_pj_options_puskesmas_id_dari_input_diabaikan_untuk_admin_puskesmas(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $pjA = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $this->makeUser('pj_prolanis', $this->puskesmasB);

        Sanctum::actingAs($admin);

        // admin_puskesmas A coba minta puskesmas_id B -- tetap dipaksa ke puskesmasnya sendiri.
        $response = $this->getJson('/api/v1/kader/pj-options?puskesmas_id='.$this->puskesmasB->id);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertEquals([$pjA->id], $ids->all());
    }

    public function test_kader_murni_ditolak_akses_pj_options(): void
    {
        $kaderUser = $this->makeUser('kader', $this->puskesmasA);
        Kader::create(['user_id' => $kaderUser->id, 'puskesmas_id' => $this->puskesmasA->id, 'status_aktif' => true, 'no_hp' => '0800']);

        Sanctum::actingAs($kaderUser);

        $this->getJson('/api/v1/kader/pj-options')->assertStatus(403);
    }

    public function test_pj_options_tanpa_login_ditolak_401(): void
    {
        $this->getJson('/api/v1/kader/pj-options')->assertStatus(401);
    }

    // ---- List ----

    public function test_admin_puskesmas_hanya_melihat_kader_puskesmas_sendiri(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $kaderA = $this->makeKader($this->puskesmasA);
        $this->makeKader($this->puskesmasB);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/kader');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertEquals([$kaderA->id], $ids->all());
    }

    public function test_super_admin_melihat_semua_kader_bisa_filter_puskesmas_id(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $kaderA = $this->makeKader($this->puskesmasA);
        $this->makeKader($this->puskesmasB);

        Sanctum::actingAs($superAdmin);

        $response = $this->getJson('/api/v1/kader?puskesmas_id='.$this->puskesmasA->id);

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertEquals([$kaderA->id], $ids->all());
    }

    public function test_kader_murni_tidak_bisa_akses_list_kader(): void
    {
        $kaderUser = $this->makeUser('kader', $this->puskesmasA);
        Kader::create(['user_id' => $kaderUser->id, 'puskesmas_id' => $this->puskesmasA->id, 'status_aktif' => true, 'no_hp' => '0800']);

        Sanctum::actingAs($kaderUser);

        $response = $this->getJson('/api/v1/kader');

        $response->assertStatus(403);
    }

    private function makeKader(Puskesmas $puskesmas): Kader
    {
        static $n = 0;
        $n++;
        $user = User::factory()->create(['puskesmas_id' => $puskesmas->id, 'email' => "kader{$n}@example.test"]);

        return Kader::create(['user_id' => $user->id, 'puskesmas_id' => $puskesmas->id, 'status_aktif' => true, 'no_hp' => '0800']);
    }

    // ---- Self-service update profile ----

    public function test_kader_bisa_update_profil_sendiri(): void
    {
        $kaderUser = $this->makeUser('kader', $this->puskesmasA);
        Kader::create(['user_id' => $kaderUser->id, 'puskesmas_id' => $this->puskesmasA->id, 'status_aktif' => true, 'no_hp' => '0800']);

        Sanctum::actingAs($kaderUser);

        $response = $this->patchJson('/api/v1/kader/profile', [
            'no_wa' => '081299999999',
            'alamat' => 'Jl. Contoh No. 1',
            'gender' => 'P',
            'tgl_lahir' => '1990-01-01',
        ]);

        $response->assertOk();
        $this->assertSame('081299999999', $response->json('data.no_wa'));

        $kader = Kader::where('user_id', $kaderUser->id)->first();
        $this->assertSame('081299999999', $kader->no_wa);
        $this->assertSame('Jl. Contoh No. 1', $kader->alamat);
        $this->assertSame('P', $kader->gender);
    }

    public function test_kader_tidak_bisa_ubah_no_hp_lewat_self_service(): void
    {
        $kaderUser = $this->makeUser('kader', $this->puskesmasA);
        Kader::create(['user_id' => $kaderUser->id, 'puskesmas_id' => $this->puskesmasA->id, 'status_aktif' => true, 'no_hp' => '0800']);

        Sanctum::actingAs($kaderUser);

        $this->patchJson('/api/v1/kader/profile', [
            'no_hp' => '089900000000',
            'no_wa' => '081299999999',
        ])->assertOk();

        $kader = Kader::where('user_id', $kaderUser->id)->first();
        $this->assertSame('0800', $kader->no_hp);
    }

    public function test_user_tanpa_profil_kader_ditolak_saat_update_profile(): void
    {
        $kaderUser = $this->makeUser('kader', $this->puskesmasA);

        Sanctum::actingAs($kaderUser);

        $response = $this->patchJson('/api/v1/kader/profile', ['no_wa' => '0812']);

        $response->assertStatus(422);
    }

    public function test_policy_update_own_profile_menolak_kader_lain(): void
    {
        $kaderUserA = $this->makeUser('kader', $this->puskesmasA);
        $kaderA = Kader::create(['user_id' => $kaderUserA->id, 'puskesmas_id' => $this->puskesmasA->id, 'status_aktif' => true, 'no_hp' => '0800']);
        $kaderUserLain = $this->makeUser('kader', $this->puskesmasA);

        $this->assertFalse($kaderUserLain->can('updateOwnProfile', $kaderA));
        $this->assertTrue($kaderUserA->can('updateOwnProfile', $kaderA));
    }
}
