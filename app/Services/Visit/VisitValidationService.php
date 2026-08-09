<?php

namespace App\Services\Visit;

use App\DTO\VisitValidationContext;
use App\DTO\VisitValidationResult;
use App\DTO\VisitValidationSummary;
use App\Services\Visit\Validation\Layers\ExifValidator;
use App\Services\Visit\Validation\Layers\FaceDetectionCheck;
use App\Services\Visit\Validation\Layers\GeofenceCheck;
use App\Services\Visit\Validation\Layers\GpsActiveCheck;
use App\Services\Visit\Validation\Layers\LiveCameraCheck;
use App\Services\Visit\Validation\Layers\OfflineQueueHandler;
use App\Services\Visit\Validation\Layers\WatermarkGenerator;
use App\Services\Visit\Validation\VisitValidationLayer;

/**
 * 7-layer validation untuk laporan kunjungan kader (docs/planning/02 §3/§10) — Strategy Pattern
 * per layer, tiap layer independen/testable/toggle-able (Open/Closed Principle). Dipakai oleh
 * VisitReportService (belum dibangun, di luar scope task ini) sebelum laporan kunjungan disimpan.
 *
 * Urutan mengikuti penomoran layer di dokumen 1-7 persis, JANGAN diubah tanpa alasan kuat —
 * mis. Watermark (4) sengaja SEBELUM ExifValidator (5) sesuai urutan dokumen, meski secara
 * intuisi validasi keaslian biasanya dilakukan sebelum foto diproses.
 */
class VisitValidationService
{
    /** @var VisitValidationLayer[] */
    private readonly array $layers;

    public function __construct(
        GpsActiveCheck $gpsActiveCheck,
        GeofenceCheck $geofenceCheck,
        LiveCameraCheck $liveCameraCheck,
        WatermarkGenerator $watermarkGenerator,
        ExifValidator $exifValidator,
        FaceDetectionCheck $faceDetectionCheck,
        OfflineQueueHandler $offlineQueueHandler,
    ) {
        $this->layers = [
            $gpsActiveCheck,
            $geofenceCheck,
            $liveCameraCheck,
            $watermarkGenerator,
            $exifValidator,
            $faceDetectionCheck,
            $offlineQueueHandler,
        ];
    }

    /**
     * Fail-fast: berhenti di layer pertama yang gagal (layer-layer ini berurutan secara
     * logis — mis. tidak ada gunanya watermark/EXIF-check foto kalau live-camera-check
     * saja sudah gagal). Layer yang dinonaktifkan (FaceDetectionCheck default) dilewati,
     * tetap tercatat di $results sebagai skipped=true untuk transparansi/audit.
     */
    public function validate(VisitValidationContext $context): VisitValidationSummary
    {
        $results = [];
        $metadata = [];

        foreach ($this->layers as $layer) {
            if (! $layer->isEnabled()) {
                $results[] = VisitValidationResult::skipped($layer->name());

                continue;
            }

            $result = $layer->validate($context);
            $results[] = $result;
            $metadata = array_merge($metadata, $result->metadata);

            if (! $result->passed) {
                return new VisitValidationSummary(false, $results, $metadata);
            }
        }

        return new VisitValidationSummary(true, $results, $metadata);
    }
}
