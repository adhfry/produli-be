<?php

namespace Tests\Feature\Auth;

use App\Models\Kabupaten;
use App\Models\Kader;
use App\Models\Puskesmas;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresi untuk Onboarding First-Login (docs/planning/02 §14) -- POST
 * /api/v1/auth/onboarding/complete dan middleware EnsureOnboardingCompleted, yang menolak akses
 * endpoint lain selagi onboarding_completed_at masih null, kecuali /auth/me, /auth/logout,
 * /auth/change-password, /auth/onboarding/complete.
 */
class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
    }

    private function makeUserBelumOnboarding(string $role = 'admin_puskesmas'): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
            'onboarding_completed_at' => null,
            'tos_accepted_at' => null,
        ]);
        $user->assignRole($role);

        return $user;
    }

    // ---- Middleware EnsureOnboardingCompleted ----

    public function test_endpoint_lain_ditolak_selagi_onboarding_belum_selesai(): void
    {
        Sanctum::actingAs($this->makeUserBelumOnboarding());

        $response = $this->getJson('/api/v1/patients');

        $response->assertStatus(403);
        $this->assertSame('ONBOARDING_REQUIRED', $response->json('data.code'));
    }

    public function test_auth_me_tetap_bisa_diakses_selagi_onboarding_belum_selesai(): void
    {
        Sanctum::actingAs($this->makeUserBelumOnboarding());

        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_auth_logout_tetap_bisa_diakses_selagi_onboarding_belum_selesai(): void
    {
        Sanctum::actingAs($this->makeUserBelumOnboarding());

        $this->postJson('/api/v1/auth/logout')->assertOk();
    }

    public function test_change_password_tetap_bisa_diakses_selagi_onboarding_belum_selesai(): void
    {
        Sanctum::actingAs($this->makeUserBelumOnboarding());

        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'new_password' => 'password-baru-yang-aman',
        ]);

        $response->assertOk();
    }

    public function test_onboarding_complete_diblokir_selagi_must_change_password_true(): void
    {
        $user = $this->makeUserBelumOnboarding();
        $user->update(['must_change_password' => true]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/onboarding/complete', []);

        $response->assertStatus(403);
        $this->assertSame('MUST_CHANGE_PASSWORD', $response->json('data.code'));
    }

    public function test_setelah_onboarding_selesai_endpoint_lain_bisa_diakses_lagi(): void
    {
        $user = $this->makeUserBelumOnboarding();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/onboarding/complete', [])->assertOk();

        // actingAs() cache user di request container -- pakai instance FRESH biar
        // onboarding_completed_at yang baru ke-refresh ikut dicek middleware.
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/patients')->assertOk();
    }

    // ---- POST /auth/onboarding/complete ----

    public function test_onboarding_complete_set_kedua_timestamp(): void
    {
        $user = $this->makeUserBelumOnboarding();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/onboarding/complete', []);

        $response->assertOk();
        $fresh = $user->fresh();
        $this->assertNotNull($fresh->onboarding_completed_at);
        $this->assertNotNull($fresh->tos_accepted_at);
        $this->assertNotNull($response->json('data.user.onboarding_completed_at'));
    }

    public function test_onboarding_complete_tanpa_login_ditolak_401(): void
    {
        $this->postJson('/api/v1/auth/onboarding/complete', [])->assertStatus(401);
    }

    public function test_onboarding_complete_reuse_kader_service_untuk_isi_profil(): void
    {
        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $puskesmas = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-A', 'nama' => 'Puskesmas A']);

        $user = $this->makeUserBelumOnboarding('kader');
        $kader = Kader::create(['user_id' => $user->id, 'puskesmas_id' => $puskesmas->id, 'status_aktif' => true, 'no_hp' => '0800']);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/onboarding/complete', [
            'no_wa' => '081234567890',
            'alamat' => 'Jl. Onboarding No. 1',
            'gender' => 'L',
            'tgl_lahir' => '1990-01-01',
        ]);

        $response->assertOk();
        $freshKader = $kader->fresh();
        $this->assertSame('081234567890', $freshKader->no_wa);
        $this->assertSame('Jl. Onboarding No. 1', $freshKader->alamat);
        $this->assertSame('L', $freshKader->gender);
        $this->assertNotNull($user->fresh()->onboarding_completed_at);
    }

    public function test_onboarding_complete_payload_profil_diabaikan_untuk_non_kader(): void
    {
        $user = $this->makeUserBelumOnboarding('admin_puskesmas');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/onboarding/complete', [
            'no_wa' => '081234567890',
        ]);

        $response->assertOk();
        $this->assertNotNull($user->fresh()->onboarding_completed_at);
    }

    public function test_onboarding_complete_validasi_gender_invalid(): void
    {
        Sanctum::actingAs($this->makeUserBelumOnboarding('kader'));

        $response = $this->postJson('/api/v1/auth/onboarding/complete', [
            'gender' => 'X',
        ]);

        $response->assertStatus(422);
    }
}
