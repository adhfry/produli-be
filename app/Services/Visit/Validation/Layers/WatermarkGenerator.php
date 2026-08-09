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

    public function isEnabled(): bool
    {
        return true;
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
            $context->kaderName !== '' ? $context->kaderName : 'Kader',
            now()->format('d-m-Y H:i:s'),
            $context->latitude,
            $context->longitude,
        );

        $padding = 12;
        $fontSize = 14;
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
