<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Logo PRODULI sebagai base64 data URI, dipakai untuk PDF export (resources/views/pdf/*) yang
 * dirender lewat DomPDF -- data URI aman & didukung penuh di sana (bukan lintas-klien-email
 * spt kasus di bawah).
 *
 * TIDAK dipakai lagi di header.blade.php (email): `$message->embed()` TERNYATA tidak pernah
 * ter-inject utk render markdown mail manapun (baik Mailable::markdown() maupun
 * MailMessage::markdown() notification, dikonfirmasi lewat pengiriman nyata), dan base64 data
 * URI sbg fallback-nya TERNYATA juga tidak reliable -- banyak klien email (terutama Outlook
 * desktop & sejumlah gateway korporat) menge-strip `<img src="data:...">` sbg kebijakan
 * keamanan. header.blade.php sekarang pakai URL absolut ke public/images/mail/logo.png
 * (di-host langsung di domain backend) -- pendekatan standar yang didukung SEMUA klien email.
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
