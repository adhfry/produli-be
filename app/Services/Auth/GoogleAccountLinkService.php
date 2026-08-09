<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tautkan/lepas akun Google untuk user yang SUDAH login via email/password. Beda dari alur
 * login Google (GoogleAuthController::redirect/callback) yang pakai Socialite session-based
 * state (butuh routes/web.php + middleware 'web') -- di sini pakai token acak sendiri di-cache
 * 5 menit -> user_id, dikirim sebagai parameter 'state' ke Socialite (mode stateless()), supaya
 * endpoint "redirect" bisa authenticated (Bearer token via routes/api.php) tanpa butuh session.
 */
class GoogleAccountLinkService
{
    private const CACHE_PREFIX = 'google_account_link:';

    private const TOKEN_TTL_MINUTES = 5;

    public function createLinkToken(User $user): string
    {
        $token = Str::random(40);

        Cache::put(self::CACHE_PREFIX.$token, $user->id, now()->addMinutes(self::TOKEN_TTL_MINUTES));

        return $token;
    }

    /**
     * Cache::pull() (bukan get()) -- token dari parameter state OAuth sekali pakai, tidak boleh
     * di-replay.
     */
    public function resolveUserFromState(?string $state): ?User
    {
        if ($state === null) {
            return null;
        }

        $userId = Cache::pull(self::CACHE_PREFIX.$state);

        return $userId ? User::find($userId) : null;
    }

    /**
     * @throws ValidationException kalau google_id/email itu sudah terpasang ke user LAIN.
     */
    public function link(User $user, string $googleId, string $googleEmail): void
    {
        $conflict = User::where('id', '!=', $user->id)
            ->where(function ($query) use ($googleId, $googleEmail) {
                $query->where('google_id', $googleId)->orWhere('email', $googleEmail);
            })
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'google' => ['Akun Google ini sudah terpasang ke user lain.'],
            ]);
        }

        $user->update(['google_id' => $googleId]);
    }

    /**
     * @throws ValidationException kalau user tidak punya password sama sekali (mencegah
     *     locked-out total dari akunnya sendiri).
     */
    public function unlink(User $user): void
    {
        if ($user->password === null) {
            throw ValidationException::withMessages([
                'google' => ['Tidak bisa melepas akun Google -- Anda belum punya password, ini akan mengunci Anda dari akun sendiri.'],
            ]);
        }

        $user->update(['google_id' => null]);
    }
}
