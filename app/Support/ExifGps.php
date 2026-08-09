<?php

namespace App\Support;

/**
 * Konversi koordinat GPS format EXIF (derajat/menit/detik sebagai pecahan "num/denum")
 * ke decimal degrees. Dipisah dari ExifValidator supaya bisa di-unit-test langsung
 * tanpa perlu file foto sungguhan ber-EXIF.
 */
class ExifGps
{
    /**
     * @param  array<int, string>  $dms  [derajat, menit, detik], tiap elemen "num/denum".
     */
    public static function dmsToDecimal(array $dms, string $ref): float
    {
        $degrees = self::fractionToFloat($dms[0] ?? '0/1');
        $minutes = self::fractionToFloat($dms[1] ?? '0/1');
        $seconds = self::fractionToFloat($dms[2] ?? '0/1');

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

        return in_array(strtoupper($ref), ['S', 'W'], true) ? -$decimal : $decimal;
    }

    public static function fractionToFloat(string $fraction): float
    {
        if (! str_contains($fraction, '/')) {
            return (float) $fraction;
        }

        [$numerator, $denominator] = explode('/', $fraction, 2);
        $denominator = (float) $denominator;

        return $denominator !== 0.0 ? ((float) $numerator) / $denominator : 0.0;
    }
}
