<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresi untuk enforcement must_change_password (docs/planning/02, siklus password) --
 * middleware EnsurePasswordChanged menolak akses endpoint lain selagi flag ini true, kecuali
 * /auth/me, /auth/logout, /auth/change-password. Juga meng-cover POST /auth/change-password.
 */
class PasswordChangeEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
    }

    private function makeUserMustChangePassword(): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('password-lama'),
            'must_change_password' => true,
        ]);
        $user->assignRole('admin_puskesmas');

        return $user;
    }

    public function test_endpoint_lain_ditolak_selagi_must_change_password_true(): void
    {
        Sanctum::actingAs($this->makeUserMustChangePassword());

        $response = $this->getJson('/api/v1/patients');

        $response->assertStatus(403);
        $this->assertSame('MUST_CHANGE_PASSWORD', $response->json('data.code'));
    }

    public function test_auth_me_tetap_bisa_diakses(): void
    {
        Sanctum::actingAs($this->makeUserMustChangePassword());

        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_auth_logout_tetap_bisa_diakses(): void
    {
        Sanctum::actingAs($this->makeUserMustChangePassword());

        $this->postJson('/api/v1/auth/logout')->assertOk();
    }

    public function test_change_password_tetap_bisa_diakses_selagi_must_change_password_true(): void
    {
        Sanctum::actingAs($this->makeUserMustChangePassword());

        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password-lama',
            'new_password' => 'password-baru-yang-aman',
        ]);

        $response->assertOk();
    }

    public function test_setelah_ganti_password_endpoint_lain_bisa_diakses_lagi(): void
    {
        $user = $this->makeUserMustChangePassword();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password-lama',
            'new_password' => 'password-baru-yang-aman',
        ])->assertOk();

        $this->assertFalse($user->fresh()->must_change_password);

        // actingAs() cache user di request container -- pakai instance FRESH biar flag
        // must_change_password yang baru ke-refresh ikut dicek middleware.
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/patients')->assertOk();
    }

    // ---- change-password validasi ----

    public function test_change_password_gagal_password_lama_salah(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password-benar')]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password-salah',
            'new_password' => 'password-baru-yang-aman',
        ]);

        $response->assertStatus(422);
        $this->assertTrue(Hash::check('password-benar', $user->fresh()->password));
    }

    public function test_change_password_gagal_password_baru_terlalu_pendek(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password-benar')]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password-benar',
            'new_password' => 'pendek',
        ]);

        $response->assertStatus(422);
    }

    public function test_change_password_gagal_password_baru_sama_dengan_lama(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password-yang-sama')]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password-yang-sama',
            'new_password' => 'password-yang-sama',
        ]);

        $response->assertStatus(422);
    }

    public function test_change_password_tanpa_login_ditolak_401(): void
    {
        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'a',
            'new_password' => 'password-baru-yang-aman',
        ]);

        $response->assertStatus(401);
    }
}
