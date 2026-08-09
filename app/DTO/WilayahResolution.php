<?php

namespace App\DTO;

/**
 * Hasil resolusi teks bebas kel_desa/kecamatan -> desa/kecamatan baku KOPIPU.
 * Lihat docs/planning/02-arsitektur-backend-kopipu-smart.md §2a.
 */
final class WilayahResolution
{
    public function __construct(
        public readonly ?int $desaId,
        public readonly ?int $kecamatanId,
        public readonly string $wilayahStatus,
    ) {}
}
