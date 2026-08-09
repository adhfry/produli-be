<?php

namespace App\Services\Visit\Validation\Support;

/**
 * Seam supaya ExifValidator testable — exif_read_data() adalah fungsi native PHP yang
 * tidak bisa di-mock langsung, jadi dibungkus di belakang interface ini.
 */
interface ExifReader
{
    /**
     * @return array<string, mixed>|false
     */
    public function read(string $path): array|false;
}
