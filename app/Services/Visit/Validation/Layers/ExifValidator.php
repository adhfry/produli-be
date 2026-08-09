<?php

namespace App\Services\Visit\Validation\Layers;

use App\DTO\VisitValidationContext;
use App\DTO\VisitValidationResult;
use App\Services\Visit\Validation\Support\ExifReader;
use App\Services\Visit\Validation\VisitValidationLayer;
use App\Support\ExifGps;
use App\Support\GeoDistance;
use Carbon\Carbon;

/**
 * Layer 5 — EXIF Validator (docs/planning/02 §3). Lapisan pertahanan kedua untuk LiveCameraCheck
 * (Layer 3): cross-check timestamp & GPS di metadata EXIF foto vs klaim device saat submit.
 * Banyak HP/aplikasi kompresi (mis. setelah lewat WA) membuang EXIF — kalau EXIF tidak ada sama
 * sekali, jangan block keras (§10 semangat: anti-fraud berlapis, bukan satu titik kegagalan).
 */
class ExifValidator implements VisitValidationLayer
{
    public function __construct(private readonly ExifReader $exifReader) {}

    public function name(): string
    {
        return 'exif';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function validate(VisitValidationContext $context): VisitValidationResult
    {
        $exif = $this->exifReader->read($context->photoPath);

        if ($exif === false) {
            return VisitValidationResult::pass($this->name(), 'Foto tidak punya metadata EXIF (umum setelah kompresi) — dilewati.', [
                'exif_present' => false,
            ]);
        }

        $metadata = ['exif_present' => true];

        if (isset($exif['DateTimeOriginal'])) {
            $takenAt = Carbon::createFromFormat('Y:m:d H:i:s', $exif['DateTimeOriginal']);
            $metadata['exif_taken_at'] = $takenAt?->toIso8601String();

            if ($takenAt !== false) {
                // abs() wajib: diffInSeconds() Carbon 3 signed (negatif kalau argumennya di masa
                // lalu), bukan selalu absolut seperti di Carbon 2.
                $ageSeconds = abs(Carbon::now()->diffInSeconds($takenAt));
                $maxAge = (int) config('kopipu.validation.exif.max_age_seconds');

                if ($ageSeconds > $maxAge) {
                    return VisitValidationResult::fail(
                        $this->name(),
                        'Foto tampaknya bukan diambil saat ini (timestamp EXIF terlalu lama).',
                        $metadata + ['exif_age_seconds' => $ageSeconds],
                    );
                }
            }
        }

        $exifGps = $this->extractGps($exif);

        if ($exifGps !== null) {
            $metadata['exif_gps'] = $exifGps;

            $distance = GeoDistance::metersBetween(
                $exifGps['lat'], $exifGps['lng'],
                $context->latitude, $context->longitude,
            );
            $metadata['exif_gps_distance_meters'] = $distance;

            $tolerance = (int) config('kopipu.validation.exif.gps_tolerance_meters');

            if ($distance > $tolerance) {
                return VisitValidationResult::fail(
                    $this->name(),
                    sprintf('GPS di metadata foto %.0fm dari lokasi submit (toleransi %dm).', $distance, $tolerance),
                    $metadata,
                );
            }
        }

        return VisitValidationResult::pass($this->name(), metadata: $metadata);
    }

    /**
     * @param  array<string, mixed>  $exif
     * @return array{lat: float, lng: float}|null
     */
    private function extractGps(array $exif): ?array
    {
        if (! isset($exif['GPSLatitude'], $exif['GPSLongitude'], $exif['GPSLatitudeRef'], $exif['GPSLongitudeRef'])) {
            return null;
        }

        return [
            'lat' => ExifGps::dmsToDecimal($exif['GPSLatitude'], $exif['GPSLatitudeRef']),
            'lng' => ExifGps::dmsToDecimal($exif['GPSLongitude'], $exif['GPSLongitudeRef']),
        ];
    }
}
