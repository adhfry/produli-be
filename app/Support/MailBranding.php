<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Logo header email di-inline sebagai base64 data URI (bukan link ke APP_URL) karena APP_URL di
 * dev/staging sering localhost -- tidak bisa diakses mail client penerima. File sumber sudah
 * di-resize ke 160x160 (resources/images/mail/logo.png) supaya base64-nya tidak membengkakkan
 * ukuran email secara berlebihan.
 */
class MailBranding
{
    public static function logoDataUri(): string
    {
        return Cache::rememberForever('mail.logo_data_uri', function () {
            $path = resource_path('images/mail/logo.png');

            return 'data:image/png;base64,'.base64_encode(file_get_contents($path));
        });
    }
}
