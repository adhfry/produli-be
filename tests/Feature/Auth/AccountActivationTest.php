<?php

namespace Tests\Feature\Auth;

use App\Mail\AccountActivationMail;
use App\Models\AccountActivation;
use App\Models\Kabupaten;
use App\Models\Puskesmas;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresi untuk alur aktivasi akun via email -- POST /api/v1/auth/activate (publik, tukar token
 * jadi password) dan POST /api/v1/auth/activate/resend (admin, invalidate token lama + kirim baru).
 */
class AccountActivationTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: User, 1: AccountActivation, 2: string}
     */
    private function makeInvitedUser(array $overrides = []): array
    {
        $user = User::factory()->create(array_merge(['password' => null], $overrides));
        $rawToken = 'raw-token-'.$user->id.'-'.bin2hex(random_bytes(8));

        $activation = AccountActivation::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addDays(7),
        ]);

        return [$user, $activation, $rawToken];
    }

    private function makeSuperAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }

    // ---- activate ----

    public function test_activate_berhasil_dengan_token_valid(): void
    {
        [$user, $activation, $rawToken] = $this->makeInvitedUser();

        $response = $this->postJson('/api/v1/auth/activate', ['token' => $rawToken]);

        $response->assertOk();
        $this->assertSame('success', $response->json('status'));
        $this->assertSame($user->email, $response->json('data.email'));
        $this->assertTrue($response->json('data.must_change_password'));

        $plainPassword = $response->json('data.password');
        $this->assertNotEmpty($plainPassword);

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check($plainPassword, $fresh->password));
        $this->assertTrue($fresh->must_change_password);
        $this->assertNotNull($activation->fresh()->used_at);
    }

    public function test_activate_gagal_token_tidak_dikenal(): void
    {
        $response = $this->postJson('/api/v1/auth/activate', ['token' => 'token-ngasal-tidak-pernah-dibuat']);

        $response->assertStatus(401);
    }

    public function test_activate_gagal_token_sudah_dipakai(): void
    {
        [, $activation, $rawToken] = $this->makeInvitedUser();
        $activation->update(['used_at' => now()]);

        $response = $this->postJson('/api/v1/auth/activate', ['token' => $rawToken]);

        $response->assertStatus(401);
    }

    public function test_activate_gagal_token_kedaluwarsa(): void
    {
        [, $activation, $rawToken] = $this->makeInvitedUser();
        $activation->update(['expires_at' => now()->subDay()]);

        $response = $this->postJson('/api/v1/auth/activate', ['token' => $rawToken]);

        $response->assertStatus(401);
    }

    public function test_setelah_activate_bisa_login_pakai_password_yang_dikembalikan(): void
    {
        [$user, , $rawToken] = $this->makeInvitedUser();

        $activateResponse = $this->postJson('/api/v1/auth/activate', ['token' => $rawToken]);
        $plainPassword = $activateResponse->json('data.password');

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $plainPassword,
            'device_id' => 'device-1',
        ]);

        $loginResponse->assertOk();
    }

    // ---- resend ----

    public function test_super_admin_bisa_resend_untuk_siapa_saja(): void
    {
        Mail::fake();
        $superAdmin = $this->makeSuperAdmin();
        [$user, $oldActivation] = $this->makeInvitedUser(['puskesmas_id' => $this->puskesmasB->id]);

        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/v1/auth/activate/resend', ['user_id' => $user->id]);

        $response->assertOk();
        Mail::assertQueued(AccountActivationMail::class, fn ($mail) => $mail->hasTo($user->email));
        $this->assertSame(0, AccountActivation::whereKey($oldActivation->id)->count());
        $this->assertSame(1, AccountActivation::where('user_id', $user->id)->count());
    }

    public function test_admin_puskesmas_bisa_resend_untuk_user_di_puskesmas_sendiri(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['puskesmas_id' => $this->puskesmasA->id]);
        $admin->assignRole('admin_puskesmas');
        [$user] = $this->makeInvitedUser(['puskesmas_id' => $this->puskesmasA->id]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/auth/activate/resend', ['user_id' => $user->id]);

        $response->assertOk();
        Mail::assertQueued(AccountActivationMail::class);
    }

    public function test_admin_puskesmas_ditolak_resend_untuk_user_puskesmas_lain(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['puskesmas_id' => $this->puskesmasA->id]);
        $admin->assignRole('admin_puskesmas');
        [$user] = $this->makeInvitedUser(['puskesmas_id' => $this->puskesmasB->id]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/auth/activate/resend', ['user_id' => $user->id]);

        $response->assertStatus(403);
        Mail::assertNothingQueued();
    }

    public function test_resend_invalidasi_token_lama_tidak_bisa_dipakai_lagi(): void
    {
        Mail::fake();
        $superAdmin = $this->makeSuperAdmin();
        [$user, , $oldRawToken] = $this->makeInvitedUser();

        Sanctum::actingAs($superAdmin);
        $this->postJson('/api/v1/auth/activate/resend', ['user_id' => $user->id])->assertOk();

        $response = $this->postJson('/api/v1/auth/activate', ['token' => $oldRawToken]);

        $response->assertStatus(401);
    }

    public function test_resend_ditolak_kalau_akun_sudah_aktif(): void
    {
        Mail::fake();
        $superAdmin = $this->makeSuperAdmin();
        $sudahAktif = User::factory()->create(); // factory default: password sudah terisi

        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/v1/auth/activate/resend', ['user_id' => $sudahAktif->id]);

        $response->assertStatus(422);
        Mail::assertNothingQueued();
    }

    public function test_kader_tidak_bisa_resend(): void
    {
        Mail::fake();
        $kaderUser = User::factory()->create(['puskesmas_id' => $this->puskesmasA->id]);
        $kaderUser->assignRole('kader');
        [$user] = $this->makeInvitedUser(['puskesmas_id' => $this->puskesmasA->id]);

        Sanctum::actingAs($kaderUser);

        $response = $this->postJson('/api/v1/auth/activate/resend', ['user_id' => $user->id]);

        $response->assertStatus(403);
    }

    public function test_resend_tanpa_login_ditolak_401(): void
    {
        [$user] = $this->makeInvitedUser();

        $response = $this->postJson('/api/v1/auth/activate/resend', ['user_id' => $user->id]);

        $response->assertStatus(401);
    }
}
