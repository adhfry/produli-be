<?php

namespace App\Services\Visit\Validation\Layers;

use App\DTO\VisitValidationContext;
use App\DTO\VisitValidationResult;
use App\Services\Visit\Validation\VisitValidationLayer;
use Carbon\Carbon;

/**
 * Layer 1 — GPS aktif (docs/planning/02 §3). Validasi akurasi & usia titik GPS saat submit,
 * BUKAN validasi lokasinya cocok dengan pasien (itu tugas GeofenceCheck/Layer 2).
 */
class GpsActiveCheck implements VisitValidationLayer
{
    public function name(): string
    {
        return 'gps_active';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function validate(VisitValidationContext $context): VisitValidationResult
    {
        // "Null Island" (0,0) — sentinel umum saat device GAGAL dapat fix GPS, bukan koordinat
        // asli (Sumenep ada di sekitar -7.x, 113.x, jauh dari 0,0).
        if ($context->latitude === 0.0 && $context->longitude === 0.0) {
            return VisitValidationResult::fail($this->name(), 'GPS tidak aktif — koordinat tidak tertangkap.');
        }

        $maxAccuracy = (int) config('produli.validation.gps.max_accuracy_meters');

        if ($context->gpsAccuracyMeters !== null && $context->gpsAccuracyMeters > $maxAccuracy) {
            return VisitValidationResult::fail(
                $this->name(),
                sprintf('Akurasi GPS terlalu rendah (%.0fm, batas %dm).', $context->gpsAccuracyMeters, $maxAccuracy),
                ['gps_accuracy_meters' => $context->gpsAccuracyMeters],
            );
        }

        if ($context->gpsCapturedAt !== null) {
            // Submission OFFLINE (draft disimpan lalu baru disinkron belakangan, bisa jam-jaman
            // kemudian) pakai threshold jauh lebih longgar -- itu cara kerja mode offline yang
            // disengaja, bukan indikasi lokasi basi/dipalsukan seperti submission online.
            $maxAge = (int) config($context->isOffline
                ? 'produli.validation.gps.max_age_seconds_offline'
                : 'produli.validation.gps.max_age_seconds');
            // abs() wajib: diffInSeconds() Carbon 3 signed (negatif kalau argumennya di masa lalu),
            // bukan selalu absolut seperti di Carbon 2.
            $ageSeconds = abs(Carbon::now()->diffInSeconds($context->gpsCapturedAt));

            if ($ageSeconds > $maxAge) {
                return VisitValidationResult::fail(
                    $this->name(),
                    'Titik GPS sudah terlalu lama — kemungkinan bukan lokasi saat ini.',
                    ['gps_age_seconds' => $ageSeconds],
                );
            }
        }

        return VisitValidationResult::pass($this->name());
    }
}
