<?php

namespace App\Services\Auth;

use App\DTO\TokenPair;
use App\Exceptions\InvalidAuthTokenException;
use App\Models\RefreshToken;
use App\Models\User;

/**
 * Penerbitan/rotasi/revoke pasangan access token (Sanctum, short-lived) + refresh token
 * (long-lived, device-bound). Lihat docs/planning/02-arsitektur-backend-produli.md §6.
 */
class AuthTokenService
{
    private const REFRESH_TOKEN_TTL_DAYS = 30;

    public function issue(User $user, string $deviceId, ?string $deviceName = null): TokenPair
    {
        $accessToken = $user->createToken('access-token')->plainTextToken;
        $accessTokenExpiresAt = now()->addMinutes((int) config('sanctum.expiration', 30));

        $rawRefreshToken = bin2hex(random_bytes(32));

        $refreshToken = RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $rawRefreshToken),
            'device_id' => $deviceId,
            'device_name' => $deviceName,
            'expires_at' => now()->addDays(self::REFRESH_TOKEN_TTL_DAYS),
        ]);

        return new TokenPair($accessToken, $rawRefreshToken, $accessTokenExpiresAt, $refreshToken->expires_at);
    }

    /**
     * Rotasi refresh token: token lama direvoke, pasangan baru diterbitkan untuk device yang sama.
     *
     * @throws InvalidAuthTokenException
     */
    public function refresh(string $rawRefreshToken, string $deviceId): TokenPair
    {
        $record = RefreshToken::where('token_hash', hash('sha256', $rawRefreshToken))->first();

        if (! $record) {
            throw new InvalidAuthTokenException('Refresh token tidak valid.');
        }

        if ($record->revoked_at !== null) {
            // Refresh token yang sudah di-rotasi/revoke dipakai lagi = indikasi kuat token dicuri.
            // Revoke SEMUA refresh token milik user ini sebagai tindakan pengamanan (§6 device binding).
            $this->revokeAllForUser($record->user_id);

            throw new InvalidAuthTokenException(
                'Refresh token terdeteksi dipakai ulang setelah di-revoke — semua sesi diamankan, silakan login ulang.'
            );
        }

        if ($record->expires_at->isPast()) {
            throw new InvalidAuthTokenException('Refresh token sudah kedaluwarsa, silakan login ulang.');
        }

        if ($record->device_id !== $deviceId) {
            // Device mismatch: token dipakai dari device berbeda dari yang aslinya — revoke, jangan dipercaya lagi.
            $record->update(['revoked_at' => now()]);

            throw new InvalidAuthTokenException('Refresh token tidak cocok dengan perangkat ini.');
        }

        // Staf yang dinonaktifkan SETELAH sesi ini terbit (StaffService::setActive() sudah
        // langsung revokeAllForUser() saat itu terjadi, tapi jaga-jaga kalau ada refresh token
        // lain yang lolos/race) -- tolak rotasi di sini juga, jangan sampai staf nonaktif tetap
        // bisa memperpanjang sesi lewat refresh walau login() baru sudah menolaknya.
        if (! $record->user->status_aktif) {
            $record->update(['revoked_at' => now()]);

            throw new InvalidAuthTokenException('Akun Anda telah dinonaktifkan. Hubungi administrator.');
        }

        $record->update(['revoked_at' => now(), 'last_used_at' => now()]);

        return $this->issue($record->user, $deviceId, $record->device_name);
    }

    public function revoke(string $rawRefreshToken): void
    {
        RefreshToken::where('token_hash', hash('sha256', $rawRefreshToken))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function revokeAllForUser(int $userId): void
    {
        RefreshToken::where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}
