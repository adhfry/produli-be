<?php

namespace App\Support;

class GeoDistance
{
    private const EARTH_RADIUS_METERS = 6371000;

    /**
     * Jarak antara 2 titik koordinat (Haversine), dalam meter.
     * Dipakai oleh GeofenceCheck dan ExifValidator.
     */
    public static function metersBetween(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_METERS * $c;
    }
}
