<?php

namespace App\Services\Visit\Validation\Layers;

use App\DTO\VisitValidationContext;
use App\DTO\VisitValidationResult;
use App\Services\Visit\Validation\VisitValidationLayer;
use App\Support\GeoDistance;

/**
 * Layer 2 — Geofence (docs/planning/02 §3). Radius toleransi mengikuti geo_status pasien
 * (§2c): titik presisi (verified) ketat, centroid desa (approximate) jauh lebih lebar karena
 * titik pembandingnya sendiri cuma perkiraan area.
 */
class GeofenceCheck implements VisitValidationLayer
{
    public function name(): string
    {
        return 'geofence';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function validate(VisitValidationContext $context): VisitValidationResult
    {
        if ($context->expectedLatitude === null || $context->expectedLongitude === null) {
            // Tidak ada titik pembanding sama sekali (geo_status=unknown, belum ada centroid
            // desa pun) — ini masalah data quality, BUKAN indikasi fraud kader. Jangan blok.
            return VisitValidationResult::pass($this->name(), 'Tidak ada titik pembanding — geofence dilewati.', [
                'geofence_skipped' => true,
            ]);
        }

        $distanceMeters = GeoDistance::metersBetween(
            $context->latitude,
            $context->longitude,
            $context->expectedLatitude,
            $context->expectedLongitude,
        );

        $radius = $context->geoStatus === 'verified'
            ? (int) config('kopipu.validation.geofence.radius_meters_verified')
            : (int) config('kopipu.validation.geofence.radius_meters_approximate');

        $metadata = ['distance_meters' => $distanceMeters, 'radius_meters' => $radius];

        if ($distanceMeters > $radius) {
            return VisitValidationResult::fail(
                $this->name(),
                sprintf('Lokasi kader %.0fm dari titik pasien (batas %dm).', $distanceMeters, $radius),
                $metadata,
            );
        }

        return VisitValidationResult::pass($this->name(), metadata: $metadata);
    }
}
