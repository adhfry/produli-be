<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Versi queued dari notifikasi reset password bawaan Laravel. Class asli
 * (Illuminate\Auth\Notifications\ResetPassword) TIDAK implements ShouldQueue -- dikirim sinkron,
 * bikin endpoint POST /api/v1/auth/forgot-password blocking nunggu SMTP selesai. Dipakai lewat
 * User::sendPasswordResetNotification() (override, lihat app/Models/User.php) sebagai pengganti
 * class aslinya, bukan lewat Password::sendResetLink() langsung.
 *
 * Isi email (subject/body/link) TETAP ikut kustomisasi ResetPassword::toMailUsing()
 * (AppServiceProvider::boot()) -- $toMailCallback itu static property yang tidak di-redeclare di
 * sini, jadi otomatis diwarisi dari parent, tidak perlu didaftarkan ulang.
 */
class QueuedResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;
}
