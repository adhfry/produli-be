<?php

namespace App\Services\Visit\Validation\Layers;

use App\DTO\VisitValidationContext;
use App\DTO\VisitValidationResult;
use App\Services\Visit\Validation\VisitValidationLayer;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;

/**
 * Layer 4 — Watermark (docs/planning/02 §3/§5). BUKAN validator penolak seperti layer lain
 * — selalu "passed", tapi memproses foto (overlay nama kader + timestamp + koordinat) sebelum
 * nantinya di-push ke S3/MinIO (proses itu sendiri tugas VisitReportService, belum dibangun —
 * Laravel yang pegang S3, bukan Nuxt, docs/planning/02 §5). Output ditulis di direktori yang
 * sama dengan foto asli (lokasi sementara sebelum upload), nama file baru supaya tidak menimpa.
 */
class WatermarkGenerator implements VisitValidationLayer
{
    public function name(): string
    {
        return 'watermark';
    }

    /**
     * NONAKTIF (laporan bug: foto yang tersimpan di server "kering" -- tidak ada logo, peta
     * mini, atau kartu lokasi seperti yang terlihat kader di auto-download/kartu review).
     * Watermark ini HANYA teks polos (nama+waktu+koordinat di kotak hitam transparan) --
     * jauh lebih sederhana dari komposit yang sudah dibangun client-side (badge logo asli,
     * thumbnail peta MapLibre live, alamat lengkap, cuaca, dll, lihat buildWatermarkComposite()
     * di app/pages/app/kunjungan/[id].vue). Foto yang disubmit SEKARANG sudah membawa komposit
     * client itu langsung (bukan lagi frame mentah) -- layer ini akan double-watermark kalau
     * tetap aktif, sekaligus tidak pernah bisa menyamai kekayaan komposit client (thumbnail peta
     * live cuma ada di browser saat momen jepretan, mustahil direkonstruksi ulang di server).
     */
    public function isEnabled(): bool
    {
        return false;
    }

    public function validate(VisitValidationContext $context): VisitValidationResult
    {
        if (! is_file($context->photoPath)) {
            return VisitValidationResult::fail($this->name(), 'File foto tidak ditemukan untuk diberi watermark.');
        }

        $manager = ImageManager::gd();
        $image = $manager->read($context->photoPath);

        $text = sprintf(
            "%s\n%s\nLat %.6f, Lng %.6f",
            $context->submitterName !== '' ? $context->submitterName : 'Kader',
            // gpsCapturedAt (momen FOTO diambil, lihat GpsActiveCheck) -- BUKAN now() (momen
            // SERVER memproses request, bisa jauh lebih telat untuk submission offline yang baru
            // disinkron belakangan; watermark yang mencantumkan waktu proses server jadi
            // menyesatkan sebagai "waktu kunjungan"). Fallback now() cuma kalau field itu null.
            ($context->gpsCapturedAt ?? now())->format('d-m-Y H:i:s'),
            $context->latitude,
            $context->longitude,
        );

        $padding = 12;
        // Proporsional terhadap lebar foto (bukan fixed 14px) -- foto beresolusi tinggi (kamera
        // HP modern, ~1920px+) bikin teks 14px fixed terlihat sangat kecil/nyaris tidak terbaca
        // (laporan lapangan nyata). round(width/40) menghasilkan skala wajar lintas resolusi,
        // dengan lantai 14px supaya foto beresolusi rendah tidak dapat font mikroskopis.
        $fontSize = max(14, (int) round($image->width() / 40));
        $lineCount = substr_count($text, "\n") + 1;
        $boxHeight = ($fontSize + 6) * $lineCount + ($padding * 2);
        $boxWidth = $image->width();
        $boxY = max(0, $image->height() - $boxHeight);

        $image->drawRectangle(0, $boxY, function ($rectangle) use ($boxWidth, $boxHeight) {
            $rectangle->size($boxWidth, (int) $boxHeight);
            $rectangle->background('rgba(0, 0, 0, 0.55)');
        });

        $image->text($text, $padding, (int) ($boxY + $padding), function ($font) use ($fontSize) {
            $font->size($fontSize);
            $font->color('#ffffff');
            $font->align('left');
            $font->valign('top');
            $font->lineHeight(1.4);
        });

        $watermarkedPath = $this->buildOutputPath($context->photoPath);
        $image->save($watermarkedPath);

        return VisitValidationResult::pass($this->name(), metadata: [
            'watermarked_photo_path' => $watermarkedPath,
        ]);
    }

    private function buildOutputPath(string $originalPath): string
    {
        $directory = dirname($originalPath);
        $extension = pathinfo($originalPath, PATHINFO_EXTENSION) ?: 'jpg';

        return $directory.DIRECTORY_SEPARATOR.'watermarked_'.Str::random(12).'.'.$extension;
    }
}
