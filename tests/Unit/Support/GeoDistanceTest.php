<?php

namespace Tests\Unit\Support;

use App\Support\GeoDistance;
use PHPUnit\Framework\TestCase;

class GeoDistanceTest extends TestCase
{
    public function test_jarak_titik_yang_sama_adalah_nol(): void
    {
        $distance = GeoDistance::metersBetween(-7.0123, 113.8456, -7.0123, 113.8456);

        $this->assertEqualsWithDelta(0.0, $distance, 0.01);
    }

    public function test_jarak_1_derajat_lintang_kira_kira_111km(): void
    {
        // 1 derajat lintang di mana pun ~111.32km — patokan yang stabil untuk sanity-check.
        $distance = GeoDistance::metersBetween(0, 0, 1, 0);

        $this->assertEqualsWithDelta(111320, $distance, 1000);
    }

    public function test_jarak_dekat_di_kabupaten_sumenep_masuk_akal(): void
    {
        // 2 titik di dalam kota Sumenep, berjarak sekitar beberapa ratus meter.
        $distance = GeoDistance::metersBetween(-7.0123, 113.8456, -7.0173, 113.8456);

        // Selisih 0.005 derajat lintang ~ 555m.
        $this->assertEqualsWithDelta(555, $distance, 20);
    }
}
