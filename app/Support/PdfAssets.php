<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Aset gambar khusus laporan PDF (kop surat) sebagai base64 data URI -- pola SAMA persis
 * MailBranding::logoDataUri() (cache 24 jam, bukan forever, supaya file yang pernah gagal
 * terbaca sekali tidak ke-cache rusak selamanya). Logo PRODULI sendiri tetap pakai
 * MailBranding::logoDataUri() (file yang sama dipakai email) -- di sini cuma logo Sumenep
 * (kuda terbang/Pegasus, lambang Kabupaten Sumenep "SUMEKAR") yang baru, ditampilkan di
 * SEBELAH KIRI logo PRODULI di kop laporan (revisi Bu Kadis).
 */
class PdfAssets
{
    public static function sumenepLogoDataUri(): string
    {
        return Cache::remember('pdf.sumenep_logo_data_uri', now()->addDay(), function () {
            $path = resource_path('images/pdf/sumenep-logo.png');

            return 'data:image/png;base64,'.base64_encode(file_get_contents($path));
        });
    }
}
