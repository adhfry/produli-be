<?php

namespace App\DTO;

use Carbon\CarbonInterface;

/**
 * Input untuk VisitValidationService — semua data yang mungkin dibutuhkan salah satu
 * dari 7 layer (docs/planning/02 §3). Immutable, tidak di-mutasi antar layer; tiap layer
 * cuma baca dari sini dan mengembalikan hasilnya sendiri lewat VisitValidationResult.
 */
final class VisitValidationContext
{
    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly ?float $gpsAccuracyMeters,
        public readonly ?CarbonInterface $gpsCapturedAt,
        public readonly ?float $expectedLatitude,
        public readonly ?float $expectedLongitude,
        public readonly string $geoStatus,
        public readonly string $photoPath,
        public readonly bool $capturedLive,
        public readonly bool $isOffline = false,
        public readonly ?string $clientSubmissionId = null,
        public readonly ?bool $faceDetectedClientSide = null,
        // Kader ATAU tenaga_kesehatan -- siapa pun pemilik assignment yang submit laporan ini
        // (VisitAssignment.kader_id XOR tenaga_kesehatan_id), dipakai WatermarkGenerator untuk
        // teks watermark foto.
        public readonly string $submitterName = '',
    ) {}
}
