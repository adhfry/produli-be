<?php

namespace App\DTO;

/**
 * Hasil resolusi teks bebas kel_desa/kecamatan -> desa/kecamatan baku PRODULI.
 * Lihat docs/planning/02-arsitektur-backend-produli.md §2a.
 */
final class WilayahResolution
{
    public function __construct(
        public readonly ?int $desaId,
        public readonly ?int $kecamatanId,
        public readonly string $wilayahStatus,
    ) {}
}
