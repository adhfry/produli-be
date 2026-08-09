<?php

namespace Tests\Feature\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const REFRESH_COOKIE = 'kopipu_refresh_token';

    public function test_login_dengan_kredensial_benar_mengeluarkan_access_token_dan_refresh_cookie(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
            'device_id' => 'device-A',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data' => ['access_token', 'token_type', 'expires_at', 'user', 'roles']]);

        // User tanpa role sama sekali -> array kosong, bukan null (getRoleNames() Spatie selalu
        // mengembalikan Collection, kosong kalau tidak ada role).
        $this->assertSame([], $response->json('data.roles'));

        $response->assertCookie(self::REFRESH_COOKIE);
        $this->assertDatabaseCount('refresh_tokens', 1);
        $this->assertDatabaseHas('refresh_tokens', ['user_id' => $user->id, 'device_id' => 'device-A']);
    }

    public function test_login_menyertakan_roles_untuk_user_dual_role_pj_prolanis_dan_kader(): void
    {
        $this->seed(\Database\Seeders\RolesSeeder::class);

        $user = User::factory()->create(['password' => Hash::make('password123')]);
        $user->assignRole('pj_prolanis', 'kader');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
            'device_id' => 'device-A',
        ]);

        $response->assertOk();
        $roles = $response->json('data.roles');

        $this->assertIsArray($roles);
        $this->assertEqualsCanonicalizing(['pj_prolanis', 'kader'], $roles);

        // Regresi: memanggil getRoleNames() sempat lazy-load & cache relasi roles() ke instance
        // User yang SAMA, lalu ikut "bocor" sebagai object Role penuh (dengan pivot) begitu
        // model itu di-serialize sebagai data.user -- harus TIDAK ADA di sana, cuma di
        // top-level data.roles (array string bersih). Lihat $hidden di app/Models/User.php.
        $this->assertArrayNotHasKey('roles', $response->json('data.user'));
    }

    public function test_me_menyertakan_roles_di_dalam_data(): void
    {
        $this->seed(\Database\Seeders\RolesSeeder::class);

        $user = User::factory()->create(['password' => Hash::make('password123')]);
        $user->assignRole('admin_puskesmas');
        $accessToken = $this->loginAs($user, 'device-A')->json('data.access_token');

        $response = $this->withHeader('Authorization', 'Bearer '.$accessToken)
            ->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonStructure(['data' => ['user', 'roles']]);
        $this->assertSame(['admin_puskesmas'], $response->json('data.roles'));
        $this->assertSame($user->email, $response->json('data.user.email'));
        $this->assertArrayNotHasKey('roles', $response->json('data.user'));
    }

    public function test_login_dengan_password_salah_gagal_422_tanpa_bocorkan_field_mana_yang_salah(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password-salah',
            'device_id' => 'device-A',
        ]);

        $response->assertStatus(422)->assertJsonPath('status', 'error');
        $this->assertDatabaseCount('refresh_tokens', 0);
    }

    public function test_login_akun_google_only_tanpa_password_tidak_bisa_login_password(): void
    {
        $user = User::factory()->create(['password' => null, 'google_id' => 'google-1']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'apapun',
            'device_id' => 'device-A',
        ]);

        $response->assertStatus(422);
    }

    public function test_refresh_valid_merotasi_token_dan_mengeluarkan_access_token_baru(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);
        $login = $this->loginAs($user, 'device-A');
        $oldAccessToken = $login->json('data.access_token');
        $rawRefreshToken = $this->extractCookieValue($login, self::REFRESH_COOKIE);

        $response = $this->withCredentials()->withUnencryptedCookies([self::REFRESH_COOKIE => $rawRefreshToken])
            ->withHeaders(['X-Device-Id' => 'device-A'])
            ->postJson('/api/v1/auth/refresh');

        $response->assertOk();
        $this->assertNotSame($oldAccessToken, $response->json('data.access_token'));

        $this->assertDatabaseCount('refresh_tokens', 2);
        $this->assertDatabaseHas('refresh_tokens', ['device_id' => 'device-A', 'revoked_at' => null]);

        $old = RefreshToken::where('token_hash', hash('sha256', $rawRefreshToken))->first();
        $this->assertNotNull($old->revoked_at);
    }

    public function test_refresh_dengan_device_id_berbeda_ditolak_dan_token_direvoke(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);
        $login = $this->loginAs($user, 'device-A');
        $rawRefreshToken = $this->extractCookieValue($login, self::REFRESH_COOKIE);

        $response = $this->withCredentials()->withUnencryptedCookies([self::REFRESH_COOKIE => $rawRefreshToken])
            ->withHeaders(['X-Device-Id' => 'device-BERBEDA'])
            ->postJson('/api/v1/auth/refresh');

        $response->assertStatus(401);

        $record = RefreshToken::where('token_hash', hash('sha256', $rawRefreshToken))->first();
        $this->assertNotNull($record->revoked_at);
    }

    public function test_refresh_token_yang_sudah_dipakai_ulang_merevoke_semua_sesi_user(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);
        $loginA = $this->loginAs($user, 'device-A');
        $loginB = $this->loginAs($user, 'device-B');
        $rawA = $this->extractCookieValue($loginA, self::REFRESH_COOKIE);

        // Pakai token device-A sekali (rotasi normal, sukses).
        $this->withCredentials()->withUnencryptedCookies([self::REFRESH_COOKIE => $rawA])
            ->withHeaders(['X-Device-Id' => 'device-A'])
            ->postJson('/api/v1/auth/refresh')
            ->assertOk();

        // Pakai LAGI raw token device-A yang sama (sudah revoked) -> reuse terdeteksi.
        $reuse = $this->withCredentials()->withUnencryptedCookies([self::REFRESH_COOKIE => $rawA])
            ->withHeaders(['X-Device-Id' => 'device-A'])
            ->postJson('/api/v1/auth/refresh');

        $reuse->assertStatus(401);

        // Semua refresh token user ini (termasuk device-B yang tidak ada masalah) harus ikut revoked.
        $this->assertDatabaseMissing('refresh_tokens', ['user_id' => $user->id, 'revoked_at' => null]);
    }

    public function test_refresh_token_kedaluwarsa_ditolak(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);
        $login = $this->loginAs($user, 'device-A');
        $rawRefreshToken = $this->extractCookieValue($login, self::REFRESH_COOKIE);

        RefreshToken::where('token_hash', hash('sha256', $rawRefreshToken))
            ->update(['expires_at' => now()->subDay()]);

        $response = $this->withCredentials()->withUnencryptedCookies([self::REFRESH_COOKIE => $rawRefreshToken])
            ->withHeaders(['X-Device-Id' => 'device-A'])
            ->postJson('/api/v1/auth/refresh');

        $response->assertStatus(401);
    }

    public function test_logout_merevoke_access_token_dan_refresh_token(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);
        $login = $this->loginAs($user, 'device-A');
        $accessToken = $login->json('data.access_token');
        $rawRefreshToken = $this->extractCookieValue($login, self::REFRESH_COOKIE);

        $this->withCredentials()->withUnencryptedCookies([self::REFRESH_COOKIE => $rawRefreshToken])
            ->withHeader('Authorization', 'Bearer '.$accessToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseHas('refresh_tokens', [
            'token_hash' => hash('sha256', $rawRefreshToken),
        ]);
        $record = RefreshToken::where('token_hash', hash('sha256', $rawRefreshToken))->first();
        $this->assertNotNull($record->revoked_at);

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Guard 'sanctum' meng-cache resolved user per instance (RequestGuard::$user) — dalam
        // 1 test method container tidak di-reboot antar call, jadi perlu di-forget manual supaya
        // request berikutnya benar-benar re-resolve dari DB, bukan pakai cache dari request logout di atas.
        $this->app['auth']->forgetGuards();

        // Access token yang sudah di-revoke tidak bisa lagi dipakai.
        $this->withHeader('Authorization', 'Bearer '.$accessToken)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    public function test_login_di_throttle_setelah_6_percobaan_per_menit(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'salah',
                'device_id' => 'device-A',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'salah',
            'device_id' => 'device-A',
        ])->assertStatus(429);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function mockGoogleUser(array $overrides = []): void
    {
        $googleUser = new SocialiteUser();
        $googleUser->id = $overrides['id'] ?? 'google-123';
        $googleUser->email = $overrides['email'] ?? 'kader.baru@example.com';
        $googleUser->name = $overrides['name'] ?? 'Kader Baru';

        $provider = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $provider->shouldReceive('user')->andReturn($googleUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_google_callback_email_tidak_terdaftar_redirect_error_tanpa_buat_user(): void
    {
        // REVISI docs/planning/02 §6: TIDAK auto-create -- akun cuma lewat registrasi resmi
        // kader/staff. Email yang belum pernah terdaftar harus ditolak, bukan dibuatkan akun.
        $this->mockGoogleUser(['email' => 'belum.terdaftar@example.com']);

        $callback = $this->get('/auth/google/callback');

        $callback->assertRedirect();
        $this->assertStringContainsString('error=account_not_found', $callback->headers->get('Location'));
        $this->assertDatabaseMissing('users', ['email' => 'belum.terdaftar@example.com']);
    }

    public function test_google_callback_email_sudah_terdaftar_tautkan_google_id_dan_exchange_menghasilkan_token(): void
    {
        // Simulasi user yang sudah terdaftar lewat registrasi kader/staff (belum pernah login
        // Google sebelumnya -- google_id masih null).
        $user = User::factory()->create(['email' => 'kader.lama@example.com', 'google_id' => null]);

        $this->mockGoogleUser(['id' => 'google-456', 'email' => 'kader.lama@example.com']);

        $callback = $this->get('/auth/google/callback');
        $callback->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'kader.lama@example.com',
            'google_id' => 'google-456',
        ]);
        // Tidak ada baris users BARU yang tercipta -- cuma google_id user yang sudah ada ditautkan.
        $this->assertSame(1, User::where('email', 'kader.lama@example.com')->count());

        parse_str((string) parse_url($callback->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('code', $query);

        $exchange = $this->postJson('/api/v1/auth/google/exchange', [
            'code' => $query['code'],
            'device_id' => 'device-google-1',
        ]);

        $exchange->assertOk()->assertJsonStructure(['data' => ['access_token']]);
        $exchange->assertCookie(self::REFRESH_COOKIE);

        // Code sekali-pakai: dipakai ulang harus gagal.
        $this->postJson('/api/v1/auth/google/exchange', [
            'code' => $query['code'],
            'device_id' => 'device-google-1',
        ])->assertStatus(401);
    }

    public function test_google_callback_user_yang_sudah_pernah_login_google_sebelumnya_diproses_biasa(): void
    {
        // google_id SUDAH tertaut dari login sebelumnya -- match langsung lewat google_id,
        // tidak perlu lagi lookup by email.
        $user = User::factory()->create(['email' => 'sudah.pernah@example.com', 'google_id' => 'google-789']);

        $this->mockGoogleUser(['id' => 'google-789', 'email' => 'sudah.pernah@example.com']);

        $callback = $this->get('/auth/google/callback');

        $callback->assertRedirect();
        $this->assertStringNotContainsString('error=', $callback->headers->get('Location'));
        $this->assertSame(1, User::where('email', 'sudah.pernah@example.com')->count());
        $this->assertSame('google-789', $user->fresh()->google_id);
    }

    private function loginAs(User $user, string $deviceId): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
            'device_id' => $deviceId,
        ])->assertOk();
    }

    private function extractCookieValue(TestResponse $response, string $name): string
    {
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === $name);

        $this->assertNotNull($cookie, "Cookie {$name} tidak ditemukan di response.");

        return $cookie->getValue();
    }
}
