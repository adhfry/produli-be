<?php

namespace Tests\Feature\Visit;

use App\DTO\VisitValidationContext;
use App\Services\Visit\VisitValidationService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class VisitValidationServiceTest extends TestCase
{
    private string $testImagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testImagePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'produli_test_'.uniqid().'.jpg';
        $image = imagecreatetruecolor(200, 200);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 100, 150, 200));
        imagejpeg($image, $this->testImagePath);
        imagedestroy($image);
    }

    protected function tearDown(): void
    {
        @unlink($this->testImagePath);

        foreach (glob(dirname($this->testImagePath).DIRECTORY_SEPARATOR.'watermarked_*') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeContext(array $overrides = []): VisitValidationContext
    {
        return new VisitValidationContext(
            latitude: $overrides['latitude'] ?? -7.0123,
            longitude: $overrides['longitude'] ?? 113.8456,
            gpsAccuracyMeters: $overrides['gpsAccuracyMeters'] ?? 10.0,
            gpsCapturedAt: $overrides['gpsCapturedAt'] ?? now(),
            expectedLatitude: array_key_exists('expectedLatitude', $overrides) ? $overrides['expectedLatitude'] : -7.0123,
            expectedLongitude: array_key_exists('expectedLongitude', $overrides) ? $overrides['expectedLongitude'] : 113.8456,
            geoStatus: $overrides['geoStatus'] ?? 'verified',
            photoPath: $overrides['photoPath'] ?? $this->testImagePath,
            capturedLive: $overrides['capturedLive'] ?? true,
            isOffline: $overrides['isOffline'] ?? false,
            clientSubmissionId: $overrides['clientSubmissionId'] ?? null,
            faceDetectedClientSide: $overrides['faceDetectedClientSide'] ?? null,
            submitterName: $overrides['submitterName'] ?? 'Bu Siti',
        );
    }

    public function test_semua_layer_lolos_menghasilkan_summary_passed(): void
    {
        $summary = app(VisitValidationService::class)->validate($this->makeContext());

        $this->assertTrue($summary->passed);
        $this->assertNull($summary->firstFailure());
        // 7 layer tercatat semua (termasuk yang skip), walau face_detection non-aktif default.
        $this->assertCount(7, $summary->results);
    }

    public function test_face_detection_nonaktif_default_tercatat_sebagai_skipped_bukan_gagal(): void
    {
        $summary = app(VisitValidationService::class)->validate($this->makeContext());

        $faceDetectionResult = collect($summary->results)->firstWhere('layer', 'face_detection');

        $this->assertNotNull($faceDetectionResult);
        $this->assertTrue($faceDetectionResult->passed);
        $this->assertTrue($faceDetectionResult->skipped);
    }

    public function test_gagal_di_layer_pertama_langsung_berhenti_fail_fast(): void
    {
        // GPS gagal (null island) -> layer 2-7 seharusnya TIDAK ikut jalan sama sekali.
        $summary = app(VisitValidationService::class)->validate($this->makeContext([
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]));

        $this->assertFalse($summary->passed);
        $this->assertSame('gps_active', $summary->firstFailure()->layer);
        $this->assertCount(1, $summary->results);
    }

    public function test_gagal_di_layer_ketiga_layer_sebelumnya_tetap_tercatat_lolos(): void
    {
        // Live camera gagal (Layer 3) -> gps_active & geofence (Layer 1-2) sudah jalan & lolos,
        // tapi watermark/exif/dst (Layer 4+) tidak ikut jalan.
        $summary = app(VisitValidationService::class)->validate($this->makeContext(['capturedLive' => false]));

        $this->assertFalse($summary->passed);
        $this->assertCount(3, $summary->results);
        $this->assertTrue($summary->results[0]->passed); // gps_active
        $this->assertTrue($summary->results[1]->passed); // geofence
        $this->assertFalse($summary->results[2]->passed); // live_camera
        $this->assertSame('live_camera', $summary->firstFailure()->layer);
    }

    public function test_metadata_dari_beberapa_layer_yang_lolos_terkumpul_di_summary(): void
    {
        $summary = app(VisitValidationService::class)->validate($this->makeContext());

        $this->assertTrue($summary->passed);
        $this->assertArrayHasKey('distance_meters', $summary->metadata); // dari GeofenceCheck
    }

    /**
     * REGRESI (laporan bug: foto tersimpan di server "kering", tanpa logo/peta/lokasi) --
     * WatermarkGenerator (Layer 4) sekarang NONAKTIF permanen (foto yang disubmit sudah
     * membawa komposit watermark client-side, lihat buildWatermarkComposite() di frontend),
     * jadi HARUS tercatat skipped, BUKAN menghasilkan watermarked_photo_path lagi -- kalau
     * layer ini diam-diam aktif lagi di masa depan, foto akan double-watermark.
     */
    public function test_watermark_generator_skipped_tidak_lagi_hasilkan_watermarked_photo_path(): void
    {
        $summary = app(VisitValidationService::class)->validate($this->makeContext());

        $this->assertTrue($summary->passed);
        $this->assertArrayNotHasKey('watermarked_photo_path', $summary->metadata);
        $this->assertTrue($summary->results[3]->skipped);
        $this->assertSame('watermark', $summary->results[3]->layer);
        $this->assertTrue($summary->results[3]->passed);
    }

    public function test_face_detection_diaktifkan_ikut_menentukan_hasil_akhir(): void
    {
        Config::set('produli.validation.face_detection_enabled', true);

        $summaryGagal = app(VisitValidationService::class)->validate($this->makeContext(['faceDetectedClientSide' => false]));
        $this->assertFalse($summaryGagal->passed);
        $this->assertSame('face_detection', $summaryGagal->firstFailure()->layer);

        $summaryLolos = app(VisitValidationService::class)->validate($this->makeContext(['faceDetectedClientSide' => true]));
        $this->assertTrue($summaryLolos->passed);
    }

    public function test_submission_offline_tanpa_client_submission_id_gagal_di_layer_terakhir(): void
    {
        $summary = app(VisitValidationService::class)->validate($this->makeContext([
            'isOffline' => true,
            'clientSubmissionId' => null,
        ]));

        $this->assertFalse($summary->passed);
        $this->assertSame('offline_queue', $summary->firstFailure()->layer);
        // Semua 6 layer sebelumnya sempat jalan & lolos duluan.
        $this->assertCount(7, $summary->results);
    }
}
