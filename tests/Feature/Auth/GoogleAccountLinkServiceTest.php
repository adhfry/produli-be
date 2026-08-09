<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\GoogleAccountLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Regresi untuk GoogleAccountLinkService -- tautkan/lepas akun Google untuk user yang sudah
 * login via email/password. Token cache (bukan Socialite session-based state) supaya endpoint
 * redirect bisa authenticated (Bearer token) -- lihat catatan kelas.
 */
class GoogleAccountLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    private GoogleAccountLinkService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(GoogleAccountLinkService::class);
    }

    public function test_create_link_token_menyimpan_ke_cache_dengan_user_id(): void
    {
        $user = User::factory()->create();

        $token = $this->service->createLinkToken($user);

        $this->assertSame($user->id, Cache::get('google_account_link:'.$token));
    }

    public function test_resolve_user_from_state_mengembalikan_user_dan_sekali_pakai(): void
    {
        $user = User::factory()->create();
        $token = $this->service->createLinkToken($user);

        $resolved = $this->service->resolveUserFromState($token);

        $this->assertNotNull($resolved);
        $this->assertSame($user->id, $resolved->id);

        // Cache::pull() -- sekali pakai, panggilan kedua harus null.
        $this->assertNull($this->service->resolveUserFromState($token));
    }

    public function test_resolve_user_from_state_null_kalau_state_kosong_atau_tidak_dikenal(): void
    {
        $this->assertNull($this->service->resolveUserFromState(null));
        $this->assertNull($this->service->resolveUserFromState('token-tidak-pernah-dibuat'));
    }

    public function test_link_berhasil_kalau_bersih(): void
    {
        $user = User::factory()->create(['google_id' => null]);

        $this->service->link($user, 'google-id-123', 'google.punya@example.test');

        $this->assertSame('google-id-123', $user->fresh()->google_id);
    }

    public function test_link_ditolak_kalau_google_id_sudah_dipakai_user_lain(): void
    {
        User::factory()->create(['google_id' => 'google-id-milik-orang-lain']);
        $user = User::factory()->create(['google_id' => null]);

        $this->expectException(ValidationException::class);

        $this->service->link($user, 'google-id-milik-orang-lain', 'lain@example.test');
    }

    public function test_link_ditolak_kalau_email_sudah_dipakai_user_lain(): void
    {
        User::factory()->create(['email' => 'sudah.ada@example.test']);
        $user = User::factory()->create(['google_id' => null]);

        $this->expectException(ValidationException::class);

        $this->service->link($user, 'google-id-baru', 'sudah.ada@example.test');
    }

    public function test_link_boleh_kalau_email_google_sama_dengan_email_sendiri(): void
    {
        $user = User::factory()->create(['email' => 'punya.sendiri@example.test', 'google_id' => null]);

        $this->service->link($user, 'google-id-baru', 'punya.sendiri@example.test');

        $this->assertSame('google-id-baru', $user->fresh()->google_id);
    }

    public function test_unlink_berhasil_kalau_punya_password(): void
    {
        $user = User::factory()->create(['google_id' => 'google-id-lama']);

        $this->service->unlink($user);

        $this->assertNull($user->fresh()->google_id);
    }

    public function test_unlink_ditolak_kalau_tidak_punya_password(): void
    {
        $user = User::factory()->create(['google_id' => 'google-id-lama', 'password' => null]);

        try {
            $this->service->unlink($user);
            $this->fail('Seharusnya melempar ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame('google-id-lama', $user->fresh()->google_id);
    }
}
