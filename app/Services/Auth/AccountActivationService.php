<?php

namespace App\Services\Auth;

use App\Exceptions\InvalidAuthTokenException;
use App\Mail\AccountActivationMail;
use App\Models\AccountActivation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Alur aktivasi akun untuk staf (admin_puskesmas/pj_prolanis, POST /api/v1/staff) dan kader
 * (POST /api/v1/kader) yang baru dibuat -- token mentah dikirim SEKALI lewat email, cuma hash-nya
 * (SHA-256) yang disimpan (pola sama persis seperti refresh_tokens.token_hash di AuthTokenService).
 */
class AccountActivationService
{
    private const TOKEN_TTL_DAYS = 7;

    /**
     * Dipanggil setelah User BARU dibuat (KaderService/StaffService mengecek
     * User::wasRecentlyCreated dulu) -- user existing (email sudah terdaftar sebelumnya) TIDAK
     * pernah lewat sini, jadi tidak pernah dikirimi ulang email aktivasi tanpa alasan.
     */
    public function inviteNewUser(User $user, User $invitedBy): AccountActivation
    {
        $rawToken = bin2hex(random_bytes(32));

        $activation = AccountActivation::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addDays(self::TOKEN_TTL_DAYS),
            'invited_by' => $invitedBy->id,
        ]);

        $this->sendInviteEmail($user, $rawToken);

        return $activation;
    }

    /**
     * "Invalidate token lama, generate baru, kirim ulang" -- token lama (belum dipakai) DIHAPUS
     * (bukan ditandai used_at, supaya used_at tetap murni berarti "user benar-benar mengaktivasi
     * akunnya", bukan "admin membatalkan token").
     */
    public function resend(User $user, User $requestedBy): AccountActivation
    {
        if ($user->password !== null) {
            throw ValidationException::withMessages([
                'user' => ['Akun ini sudah aktif, tidak perlu kirim ulang aktivasi.'],
            ]);
        }

        AccountActivation::where('user_id', $user->id)->whereNull('used_at')->delete();

        return $this->inviteNewUser($user, $requestedBy);
    }

    /**
     * @return array{user: User, plain_password: string}
     */
    public function activate(string $rawToken): array
    {
        $activation = AccountActivation::where('token_hash', hash('sha256', $rawToken))->first();

        if (! $activation) {
            throw new InvalidAuthTokenException('Token aktivasi tidak valid.');
        }

        if ($activation->used_at !== null) {
            throw new InvalidAuthTokenException('Token aktivasi sudah pernah dipakai.');
        }

        if ($activation->expires_at->isPast()) {
            throw new InvalidAuthTokenException('Token aktivasi sudah kedaluwarsa — minta admin kirim ulang.');
        }

        $plainPassword = Str::password(16);

        $activation->user->update([
            // Cast 'hashed' di User model otomatis meng-hash nilai plaintext ini saat disimpan.
            'password' => $plainPassword,
            'must_change_password' => true,
        ]);

        $activation->update(['used_at' => now()]);

        return ['user' => $activation->user->fresh(), 'plain_password' => $plainPassword];
    }

    private function sendInviteEmail(User $user, string $rawToken): void
    {
        $activationUrl = rtrim((string) config('app.frontend_url'), '/').'/activate?token='.$rawToken;

        Mail::to($user->email)->queue(new AccountActivationMail($user->name, $activationUrl));
    }
}
