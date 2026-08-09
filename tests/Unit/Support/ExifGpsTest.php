<?php

namespace Tests\Unit\Support;

use App\Support\ExifGps;
use PHPUnit\Framework\TestCase;

class ExifGpsTest extends TestCase
{
    public function test_konversi_dms_ke_desimal_untuk_belahan_utara_timur(): void
    {
        // 7°30'0" N -> 7.5, 113°15'0" E -> 113.25 (tanda tetap positif untuk N/E).
        $lat = ExifGps::dmsToDecimal(['7/1', '30/1', '0/1'], 'N');
        $lng = ExifGps::dmsToDecimal(['113/1', '15/1', '0/1'], 'E');

        $this->assertEqualsWithDelta(7.5, $lat, 0.0001);
        $this->assertEqualsWithDelta(113.25, $lng, 0.0001);
    }

    public function test_konversi_dms_ke_desimal_untuk_belahan_selatan_barat_membalik_tanda(): void
    {
        // Sumenep ada di belahan selatan (S) — tanda harus jadi negatif.
        $lat = ExifGps::dmsToDecimal(['7/1', '30/1', '0/1'], 'S');
        $lng = ExifGps::dmsToDecimal(['113/1', '15/1', '0/1'], 'W');

        $this->assertEqualsWithDelta(-7.5, $lat, 0.0001);
        $this->assertEqualsWithDelta(-113.25, $lng, 0.0001);
    }

    public function test_referensi_huruf_kecil_tetap_dikenali(): void
    {
        $lat = ExifGps::dmsToDecimal(['7/1', '0/1', '0/1'], 's');

        $this->assertEqualsWithDelta(-7.0, $lat, 0.0001);
    }

    public function test_fraction_to_float_menangani_pecahan_dan_angka_polos(): void
    {
        $this->assertEqualsWithDelta(0.5, ExifGps::fractionToFloat('1/2'), 0.0001);
        $this->assertEqualsWithDelta(30.0, ExifGps::fractionToFloat('30'), 0.0001);
        $this->assertSame(0.0, ExifGps::fractionToFloat('5/0')); // denum 0, jangan division by zero error
    }
}
