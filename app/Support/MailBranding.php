<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Fallback logo header email sebagai base64 data URI, dipakai lewat guard `isset($message)` di
 * resources/views/vendor/mail/html/header.blade.php. Awalnya niatnya: pakai
 * `$message->embed()` (Illuminate\Mail\Message, CID attachment -- kompatibilitas klien email
 * lebih luas, terutama Outlook desktop) untuk Mailable markdown biasa (AccountActivationMail,
 * VisitAssignedMail), base64 ini cuma fallback untuk Notification berbasis
 * Illuminate\Notifications\Messages\MailMessage::markdown() (mis. reset password) yang dirender
 * langsung lewat Markdown::render() TANPA lewat Mailer::send(). TERNYATA (dikonfirmasi lewat
 * pengiriman email nyata) `$message` TIDAK ter-inject untuk KEDUA jalur render markdown itu --
 * jadi base64 di sini yang SELALU dipakai saat ini, bukan cuma fallback. Guard isset() di blade
 * tetap dipertahankan (aman, tidak pernah exception) kalau suatu saat Laravel/pola rendering
 * berubah dan $message benar-benar tersedia.
 *
 * File sumber sudah di-resize ke 160x160 (resources/images/mail/logo.png) supaya base64-nya
 * tidak membengkakkan ukuran email secara berlebihan. Cache TIDAK forever (24 jam) -- supaya
 * kalau file logo pernah gagal terbaca sekali (mis. race condition saat deploy), tidak
 * ke-cache rusak selamanya.
 */
class MailBranding
{
    public static function logoDataUri(): string
    {
        return Cache::remember('mail.logo_data_uri', now()->addDay(), function () {
            $path = resource_path('images/mail/logo.png');

            return 'data:image/png;base64,'.base64_encode(file_get_contents($path));
        });
    }
}
