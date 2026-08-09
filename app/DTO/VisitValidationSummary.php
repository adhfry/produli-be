<?php

namespace App\DTO;

/**
 * Agregat hasil semua layer (docs/planning/02 §3). $metadata gabungan dari semua layer yang
 * benar-benar jalan (mis. watermarked_photo_path dari WatermarkGenerator, distance_meters dari
 * GeofenceCheck) — dipakai oleh pemanggil (VisitReportService, belum dibangun) untuk melanjutkan
 * proses simpan laporan kunjungan.
 */
final class VisitValidationSummary
{
    /**
     * @param  VisitValidationResult[]  $results
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly bool $passed,
        public readonly array $results,
        public readonly array $metadata = [],
    ) {}

    /**
     * Hasil layer pertama yang gagal — null kalau semua lolos.
     */
    public function firstFailure(): ?VisitValidationResult
    {
        foreach ($this->results as $result) {
            if (! $result->passed) {
                return $result;
            }
        }

        return null;
    }
}
