<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

/**
 * Regresi untuk endpoint tautkan/lepas akun Google lewat jalur HTTP asli (docs/planning/02,
 * setelah siklus password). Socialite::fake() dipakai untuk sisi callback (butuh Google user
 * tanpa panggilan jaringan asli) -- sisi redirect TIDAK perlu di-fake karena redirect() cuma
 * membangun URL string, tidak ada panggilan HTTP keluar sama sekali.
 */
class GoogleAccountLinkControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
    }

    private function makeLoggedInUser(array $overrides = []): User
    {
        $user = User::factory()->create($overrides);
        $user->assignRole('admin_puskesmas');

        return $user;
    }

    // ---- link/redirect ----

    public function test_link_redirect_mengembalikan_url_dan_menyimpan_token_di_cache(): void
    {
        Sanctum::actingAs($this->makeLoggedInUser());

        $response = $this->getJson('/api/v1/auth/google/link/redirect');

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.redirect_url'));
    }

    public function test_link_redirect_tanpa_login_ditolak_401(): void
    {
        $this->getJson('/api/v1/auth/google/link/redirect')->assertStatus(401);
    }

    public function test_link_redirect_ditolak_selagi_must_change_password_true(): void
    {
        Sanctum::actingAs($this->makeLoggedInUser(['must_change_password' => true]));

        $response = $this->getJson('/api/v1/auth/google/link/redirect');

        $response->assertStatus(403);
        $this->assertSame('MUST_CHANGE_PASSWORD', $response->json('data.code'));
    }

    // ---- link/callback ----

    public function test_link_callback_berhasil_set_google_id(): void
    {
        $user = $this->makeLoggedInUser();
        $token = 'state-token-'.$user->id;
        Cache::put('google_account_link:'.$token, $user->id, now()->addMinutes(5));

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-id-baru',
            'email' => 'akun.google@example.test',
            'name' => 'Nama Google',
        ]));

        $response = $this->get('/auth/google/link/callback?state='.$token.'&code=dummy-code');

        $response->assertRedirect();
        $this->assertStringContainsString('google_link=success', $response->headers->get('Location'));
        $this->assertSame('google-id-baru', $user->fresh()->google_id);
    }

    public function test_link_callback_gagal_state_tidak_valid(): void
    {
        Socialite::fake('google', SocialiteUser::fake());

        $response = $this->get('/auth/google/link/callback?state=state-ngasal&code=dummy-code');

        $response->assertRedirect();
        $this->assertStringContainsString('google_link=error', $response->headers->get('Location'));
        $this->assertStringContainsString('reason=expired_or_invalid_state', $response->headers->get('Location'));
    }

    public function test_link_callback_gagal_karena_konflik_email(): void
    {
        $userLain = User::factory()->create(['email' => 'sudah.dipakai@example.test']);
        $user = $this->makeLoggedInUser();
        $token = 'state-token-'.$user->id;
        Cache::put('google_account_link:'.$token, $user->id, now()->addMinutes(5));

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-id-lain',
            'email' => 'sudah.dipakai@example.test',
        ]));

        $response = $this->get('/auth/google/link/callback?state='.$token.'&code=dummy-code');

        $response->assertRedirect();
        $this->assertStringContainsString('google_link=error', $response->headers->get('Location'));
        $this->assertStringContainsString('reason=conflict', $response->headers->get('Location'));
        $this->assertNull($user->fresh()->google_id);
        $this->assertNotSame('google-id-lain', $userLain->fresh()->google_id);
    }

    // ---- unlink ----

    public function test_unlink_berhasil(): void
    {
        $user = $this->makeLoggedInUser(['google_id' => 'google-id-lama']);
        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/v1/auth/google/unlink');

        $response->assertOk();
        $this->assertNull($user->fresh()->google_id);
    }

    public function test_unlink_ditolak_tanpa_password(): void
    {
        $user = $this->makeLoggedInUser(['google_id' => 'google-id-lama', 'password' => null]);
        $this->assertNull($user->fresh()->password);
        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/v1/auth/google/unlink');

        $response->assertStatus(422);
        $this->assertSame('google-id-lama', $user->fresh()->google_id);
    }

    public function test_unlink_tanpa_login_ditolak_401(): void
    {
        $this->deleteJson('/api/v1/auth/google/unlink')->assertStatus(401);
    }
}
