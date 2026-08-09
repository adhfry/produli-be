<?php

namespace Tests\Feature\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use App\Notifications\QueuedResetPasswordNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Regresi untuk forgot/reset password (docs/planning/02, siklus password) -- pakai Password
 * Broker bawaan Laravel (password_reset_tokens), BUKAN infrastruktur token baru. Notification::fake()
 * dipakai (bukan Mail::fake()) karena ResetPassword::toMailUsing() (AppServiceProvider) kembalikan
 * MailMessage mentah, bukan instance Mailable -- MailFake mengabaikan non-Mailable secara diam-diam.
 */
class ForgotResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_mengirim_notifikasi_untuk_email_terdaftar(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ada@example.test']);

        $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ada@example.test']);

        $response->assertOk();
        $this->assertSame('success', $response->json('status'));

        // QueuedResetPasswordNotification (bukan ResetPassword bawaan Laravel langsung) --
        // User::sendPasswordResetNotification() di-override supaya versi ShouldQueue yang
        // terkirim, biar endpoint ini tidak blocking nunggu SMTP (lihat app/Models/User.php).
        Notification::assertSentTo(
            $user,
            QueuedResetPasswordNotification::class,
            fn ($notification) => $notification instanceof ShouldQueue,
        );
    }

    public function test_forgot_password_email_tidak_terdaftar_tetap_sukses_generik(): void
    {
        // Cegah user enumeration -- respons sukses SAMA baik email terdaftar atau tidak.
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'tidak.terdaftar@example.test']);

        $response->assertOk();
        $this->assertSame('success', $response->json('status'));
        Notification::assertNothingSent();
    }

    public function test_reset_password_berhasil_matikan_must_change_password_dan_revoke_refresh_token(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password-lama'),
            'must_change_password' => true,
        ]);
        RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'raw-refresh-token'),
            'device_id' => 'device-1',
            'expires_at' => now()->addDays(30),
        ]);

        $token = Password::createToken($user);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'password-baru-yang-aman',
            'password_confirmation' => 'password-baru-yang-aman',
        ]);

        $response->assertOk();

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('password-baru-yang-aman', $fresh->password));
        $this->assertFalse($fresh->must_change_password);

        // Reset password = sinyal keamanan -- SEMUA refresh token (device manapun) di-revoke,
        // sama seperti reuse-detection di AuthTokenService::refresh().
        $this->assertSame(0, RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->count());
    }

    public function test_reset_password_gagal_token_tidak_valid(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'token-ngasal-tidak-pernah-dibuat',
            'email' => $user->email,
            'password' => 'password-baru-yang-aman',
            'password_confirmation' => 'password-baru-yang-aman',
        ]);

        $response->assertStatus(422);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_reset_password_gagal_konfirmasi_password_tidak_cocok(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'password-baru-yang-aman',
            'password_confirmation' => 'tidak-cocok-sama-sekali',
        ]);

        $response->assertStatus(422);
    }
}
