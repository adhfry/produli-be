<?php

namespace App\Services\Visit\Validation\Support;

class NativeExifReader implements ExifReader
{
    public function read(string $path): array|false
    {
        if (! is_file($path)) {
            return false;
        }

        return @exif_read_data($path);
    }
}
