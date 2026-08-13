<?php

namespace App\Services\Auth;

use App\Mail\AdminPasswordResetMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Reset password TRIGGERED BY ADMIN (super_admin), beda dari alur self-service AuthController::
 * forgotPassword/resetPassword (itu link, dipicu & dikonfirmasi user sendiri). Di sini password
 * baru LANGSUNG di-generate sistem dan dikirim APA ADANYA lewat email (bukan link) -- dipakai
 * saat staf/kader/tenaga_kesehatan lupa password dan minta admin turun tangan. Pola generate +
 * must_change_password sama persis dengan AccountActivationService::activate().
 */
class AdminPasswordResetService
{
    public function __construct(private readonly AuthTokenService $tokens) {}

    public function reset(User $target, User $resetBy): string
    {
        $plainPassword = Str::password(16);

        $target->update([
            // Cast 'hashed' di User model otomatis meng-hash nilai plaintext ini saat disimpan.
            'password' => $plainPassword,
            'must_change_password' => true,
        ]);

        // Sinyal keamanan (password diganti paksa oleh admin) -- paksa re-login di semua device,
        // pola sama seperti AuthController::resetPassword().
        $this->tokens->revokeAllForUser($target->id);

        Mail::to($target->email)->queue(new AdminPasswordResetMail($target->name, $plainPassword, $resetBy->name));

        return $plainPassword;
    }
}
