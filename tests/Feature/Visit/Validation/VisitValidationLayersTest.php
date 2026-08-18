<?php

namespace Tests\Feature\Visit\Validation;

use App\DTO\VisitValidationContext;
use App\Services\Visit\Validation\Layers\ExifValidator;
use App\Services\Visit\Validation\Layers\FaceDetectionCheck;
use App\Services\Visit\Validation\Layers\GeofenceCheck;
use App\Services\Visit\Validation\Layers\GpsActiveCheck;
use App\Services\Visit\Validation\Layers\LiveCameraCheck;
use App\Services\Visit\Validation\Layers\OfflineQueueHandler;
use App\Services\Visit\Validation\Layers\WatermarkGenerator;
use App\Services\Visit\Validation\Support\ExifReader;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class VisitValidationLayersTest extends TestCase
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

    // --- Layer 1: GpsActiveCheck ---

    public function test_gps_active_check_pass_untuk_koordinat_wajar(): void
    {
        $result = app(GpsActiveCheck::class)->validate($this->makeContext());

        $this->assertTrue($result->passed);
    }

    public function test_gps_active_check_gagal_untuk_null_island(): void
    {
        $result = app(GpsActiveCheck::class)->validate($this->makeContext(['latitude' => 0.0, 'longitude' => 0.0]));

        $this->assertFalse($result->passed);
    }

    public function test_gps_active_check_gagal_akurasi_terlalu_rendah(): void
    {
        $result = app(GpsActiveCheck::class)->validate($this->makeContext(['gpsAccuracyMeters' => 500.0]));

        $this->assertFalse($result->passed);
    }

    public function test_gps_active_check_gagal_titik_gps_terlalu_lama(): void
    {
        $result = app(GpsActiveCheck::class)->validate($this->makeContext(['gpsCapturedAt' => now()->subHour()]));

        $this->assertFalse($result->passed);
    }

    /**
     * Threshold OFFLINE jauh lebih longgar (default 48 jam) daripada online (default 30 menit) --
     * draft yang disimpan lalu baru disinkron berjam-jam kemudian bukan indikasi lokasi
     * basi/dipalsukan, itu cara kerja mode offline yang disengaja.
     */
    public function test_gps_active_check_pass_untuk_submission_offline_walau_titik_gps_berjam_jam_lalu(): void
    {
        $result = app(GpsActiveCheck::class)->validate($this->makeContext([
            'gpsCapturedAt' => now()->subHours(10),
            'isOffline' => true,
        ]));

        $this->assertTrue($result->passed);
    }

    public function test_gps_active_check_gagal_untuk_submission_offline_kalau_melebihi_threshold_offline(): void
    {
        $result = app(GpsActiveCheck::class)->validate($this->makeContext([
            'gpsCapturedAt' => now()->subDays(3),
            'isOffline' => true,
        ]));

        $this->assertFalse($result->passed);
    }

    public function test_gps_active_check_pass_untuk_submission_online_dalam_threshold_baru_30_menit(): void
    {
        // Revisi: 300 -> 1800 detik (laporan lapangan "Titik GPS Sudah Terlalu Lama") --
        // mengakomodasi waktu wajar mengisi form pemeriksaan klinis setelah foto diambil.
        $result = app(GpsActiveCheck::class)->validate($this->makeContext([
            'gpsCapturedAt' => now()->subMinutes(20),
            'isOffline' => false,
        ]));

        $this->assertTrue($result->passed);
    }

    // --- Layer 2: GeofenceCheck ---

    public function test_geofence_check_pass_dalam_radius(): void
    {
        $result = app(GeofenceCheck::class)->validate($this->makeContext());

        $this->assertTrue($result->passed);
        $this->assertArrayHasKey('distance_meters', $result->metadata);
    }

    public function test_geofence_check_gagal_di_luar_radius_verified(): void
    {
        // ~1.1km dari titik pasien, radius verified default 150m.
        $result = app(GeofenceCheck::class)->validate($this->makeContext([
            'geoStatus' => 'verified',
            'expectedLatitude' => -7.0123,
            'expectedLongitude' => 113.8456,
            'latitude' => -7.0223,
            'longitude' => 113.8456,
        ]));

        $this->assertFalse($result->passed);
    }

    public function test_geofence_check_pass_untuk_jarak_sama_kalau_status_approximate(): void
    {
        // Jarak yang SAMA (gagal untuk verified) harus tetap lolos untuk approximate (radius 3000m).
        $result = app(GeofenceCheck::class)->validate($this->makeContext([
            'geoStatus' => 'approximate',
            'expectedLatitude' => -7.0123,
            'expectedLongitude' => 113.8456,
            'latitude' => -7.0223,
            'longitude' => 113.8456,
        ]));

        $this->assertTrue($result->passed);
    }

    public function test_geofence_check_pass_kalau_tidak_ada_titik_pembanding(): void
    {
        $result = app(GeofenceCheck::class)->validate($this->makeContext([
            'expectedLatitude' => null,
            'expectedLongitude' => null,
        ]));

        $this->assertTrue($result->passed);
        $this->assertTrue($result->metadata['geofence_skipped'] ?? false);
    }

    // --- Layer 3: LiveCameraCheck ---

    public function test_live_camera_check_pass_kalau_captured_live(): void
    {
        $result = app(LiveCameraCheck::class)->validate($this->makeContext(['capturedLive' => true]));

        $this->assertTrue($result->passed);
    }

    public function test_live_camera_check_gagal_kalau_bukan_dari_kamera(): void
    {
        $result = app(LiveCameraCheck::class)->validate($this->makeContext(['capturedLive' => false]));

        $this->assertFalse($result->passed);
    }

    // --- Layer 4: WatermarkGenerator ---

    public function test_watermark_generator_selalu_pass_dan_hasilkan_file_baru(): void
    {
        $result = app(WatermarkGenerator::class)->validate($this->makeContext());

        $this->assertTrue($result->passed);
        $this->assertArrayHasKey('watermarked_photo_path', $result->metadata);
        $this->assertFileExists($result->metadata['watermarked_photo_path']);
        $this->assertNotSame($this->testImagePath, $result->metadata['watermarked_photo_path']);

        // Pastikan hasilnya benar-benar gambar valid, bukan cuma file kosong.
        $info = getimagesize($result->metadata['watermarked_photo_path']);
        $this->assertNotFalse($info);
    }

    public function test_watermark_generator_gagal_kalau_file_foto_tidak_ada(): void
    {
        $result = app(WatermarkGenerator::class)->validate($this->makeContext(['photoPath' => '/tmp/tidak-ada-file-ini.jpg']));

        $this->assertFalse($result->passed);
    }

    // --- Layer 5: ExifValidator ---

    public function test_exif_validator_pass_kalau_tidak_ada_metadata_exif(): void
    {
        $fake = new class implements ExifReader
        {
            public function read(string $path): array|false
            {
                return false;
            }
        };
        $this->app->instance(ExifReader::class, $fake);

        $result = app(ExifValidator::class)->validate($this->makeContext());

        $this->assertTrue($result->passed);
        $this->assertFalse($result->metadata['exif_present']);
    }

    public function test_exif_validator_gagal_kalau_timestamp_terlalu_lama(): void
    {
        $fake = new class implements ExifReader
        {
            public function read(string $path): array|false
            {
                return ['DateTimeOriginal' => now()->subHour()->format('Y:m:d H:i:s')];
            }
        };
        $this->app->instance(ExifReader::class, $fake);

        $result = app(ExifValidator::class)->validate($this->makeContext());

        $this->assertFalse($result->passed);
    }

    public function test_exif_validator_pass_kalau_gps_exif_cocok_dengan_device(): void
    {
        $fake = new class implements ExifReader
        {
            public function read(string $path): array|false
            {
                return [
                    'DateTimeOriginal' => now()->format('Y:m:d H:i:s'),
                    'GPSLatitude' => ['7/1', '0/1', '0/1'],
                    'GPSLatitudeRef' => 'S',
                    'GPSLongitude' => ['113/1', '30/1', '0/1'],
                    'GPSLongitudeRef' => 'E',
                ];
            }
        };
        $this->app->instance(ExifReader::class, $fake);

        // -7.0, 113.5 -- device di titik yang sama persis.
        $result = app(ExifValidator::class)->validate($this->makeContext(['latitude' => -7.0, 'longitude' => 113.5]));

        $this->assertTrue($result->passed);
    }

    public function test_exif_validator_gagal_kalau_gps_exif_jauh_dari_device(): void
    {
        $fake = new class implements ExifReader
        {
            public function read(string $path): array|false
            {
                return [
                    'DateTimeOriginal' => now()->format('Y:m:d H:i:s'),
                    'GPSLatitude' => ['7/1', '0/1', '0/1'],
                    'GPSLatitudeRef' => 'S',
                    'GPSLongitude' => ['113/1', '30/1', '0/1'],
                    'GPSLongitudeRef' => 'E',
                ];
            }
        };
        $this->app->instance(ExifReader::class, $fake);

        // Device 1 derajat lintang dari EXIF (~111km) -- jauh melebihi toleransi 200m.
        $result = app(ExifValidator::class)->validate($this->makeContext(['latitude' => -8.0, 'longitude' => 113.5]));

        $this->assertFalse($result->passed);
    }

    // --- Layer 6: FaceDetectionCheck ---

    public function test_face_detection_check_nonaktif_secara_default(): void
    {
        $this->assertFalse(app(FaceDetectionCheck::class)->isEnabled());
    }

    public function test_face_detection_check_pass_kalau_diaktifkan_dan_wajah_terdeteksi(): void
    {
        Config::set('produli.validation.face_detection_enabled', true);

        $layer = app(FaceDetectionCheck::class);
        $this->assertTrue($layer->isEnabled());

        $result = $layer->validate($this->makeContext(['faceDetectedClientSide' => true]));
        $this->assertTrue($result->passed);
    }

    public function test_face_detection_check_gagal_kalau_diaktifkan_tapi_wajah_tidak_terdeteksi(): void
    {
        Config::set('produli.validation.face_detection_enabled', true);

        $result = app(FaceDetectionCheck::class)->validate($this->makeContext(['faceDetectedClientSide' => false]));

        $this->assertFalse($result->passed);
    }

    // --- Layer 7: OfflineQueueHandler ---

    public function test_offline_queue_handler_pass_kalau_online(): void
    {
        $result = app(OfflineQueueHandler::class)->validate($this->makeContext(['isOffline' => false]));

        $this->assertTrue($result->passed);
    }

    public function test_offline_queue_handler_gagal_kalau_offline_tanpa_submission_id(): void
    {
        $result = app(OfflineQueueHandler::class)->validate($this->makeContext(['isOffline' => true, 'clientSubmissionId' => null]));

        $this->assertFalse($result->passed);
    }

    public function test_offline_queue_handler_pass_kalau_offline_dengan_submission_id(): void
    {
        $result = app(OfflineQueueHandler::class)->validate($this->makeContext([
            'isOffline' => true,
            'clientSubmissionId' => 'client-uuid-123',
        ]));

        $this->assertTrue($result->passed);
        $this->assertSame('client-uuid-123', $result->metadata['client_submission_id']);
    }
}
