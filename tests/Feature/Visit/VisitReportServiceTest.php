<?php

namespace Tests\Feature\Visit;

use App\DTO\VisitValidationContext;
use App\Jobs\SyncFieldUpdateToSilakesJob;
use App\Models\Kabupaten;
use App\Models\Kader;
use App\Models\PatientsCache;
use App\Models\Puskesmas;
use App\Models\User;
use App\Models\VisitAssignment;
use App\Models\VisitReport;
use App\Services\Visit\VisitReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Regresi untuk VisitReportService::submit() (docs/planning/02 §2c) — laporan kunjungan kader
 * HARUS selalu tersimpan lokal meski SiLAKES down/lambat. Kegagalan panggilan SiLAKES tidak
 * boleh pernah menggagalkan submit() ini — dijamin secara arsitektur karena job sync ke SiLAKES
 * dispatch TERPISAH (afterCommit), bukan dipanggil sinkron di dalam submit().
 */
class VisitReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private VisitReportService $service;

    private VisitAssignment $assignment;

    private PatientsCache $patient;

    private Kader $kader;

    private string $testImagePath;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        $this->service = app(VisitReportService::class);

        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $puskesmas = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-A', 'nama' => 'Puskesmas A']);

        $kaderUser = User::factory()->create(['name' => 'Bu Siti']);
        $this->kader = Kader::create(['user_id' => $kaderUser->id, 'puskesmas_id' => $puskesmas->id, 'status_aktif' => true]);

        $this->patient = PatientsCache::create([
            'external_patient_id' => 950001,
            'nik_hash' => 'HASH-950001',
            'nama' => 'Pasien Uji',
            'wilayah_status' => 'resolved',
            'puskesmas_id' => $puskesmas->id,
            'geo_status' => 'approximate',
            'geo_source' => 'desa_centroid',
            'latitude' => -7.0123,
            'longitude' => 113.8456,
        ]);

        $this->assignment = VisitAssignment::create([
            'patient_id' => $this->patient->id,
            'kader_id' => $this->kader->id,
            'scheduled_date' => '2026-08-05',
            'status' => 'pending',
            'priority' => 'sedang',
            'puskesmas_id_snapshot' => $puskesmas->id,
        ]);

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
            kaderName: $overrides['kaderName'] ?? 'Bu Siti',
        );
    }

    public function test_submit_berhasil_menyimpan_laporan_dan_assignment_jadi_completed(): void
    {
        Queue::fake();

        $report = $this->service->submit($this->assignment, $this->makeContext(), 'Kondisi stabil, gula darah terkontrol.');

        $this->assertInstanceOf(VisitReport::class, $report);
        $this->assertSame('pending', $report->sync_status);
        $this->assertSame('completed', $this->assignment->fresh()->status);
        $this->assertSame('Kondisi stabil, gula darah terkontrol.', $report->kondisi);

        // photo_path menyimpan KEY di disk S3/MinIO (docs/planning/02 §5), bukan path lokal --
        // prefix 'pasien/' (taksonomi kategori bucket) + 'visit-photos/' (partisi tanggal).
        Storage::disk('s3')->assertExists($report->photo_path);
        $this->assertStringStartsWith('pasien/visit-photos/', $report->photo_path);
    }

    public function test_submit_tanpa_konfirmasi_lokasi_tidak_dispatch_job_sync(): void
    {
        Queue::fake();

        $this->service->submit($this->assignment, $this->makeContext(), 'Kondisi stabil.', confirmedPatientLocation: false);

        Queue::assertNotPushed(SyncFieldUpdateToSilakesJob::class);
        // Tidak ada geo baru yang dikonfirmasi -> geo pasien tidak berubah.
        $this->assertSame('approximate', $this->patient->fresh()->geo_status);
    }

    public function test_submit_dengan_konfirmasi_lokasi_update_geo_pasien_dan_dispatch_job(): void
    {
        Queue::fake();

        $report = $this->service->submit(
            $this->assignment,
            $this->makeContext(['latitude' => -7.0200, 'longitude' => 113.8500, 'expectedLatitude' => -7.0123, 'expectedLongitude' => 113.8456, 'geoStatus' => 'approximate']),
            'Kondisi stabil.',
            confirmedPatientLocation: true,
        );

        $patient = $this->patient->fresh();
        $this->assertSame('verified', $patient->geo_status);
        $this->assertSame('kader_verified', $patient->geo_source);
        $this->assertSame($this->kader->user_id, $patient->geo_verified_by);
        $this->assertNotNull($patient->geo_verified_at);
        $this->assertEquals(-7.0200, (float) $patient->latitude);

        Queue::assertPushed(SyncFieldUpdateToSilakesJob::class, fn ($job) => $job->visitReportId === $report->id);
    }

    public function test_submit_dengan_usulan_data_pasien_tersimpan_dan_dispatch_job_meski_tanpa_konfirmasi_lokasi(): void
    {
        Queue::fake();

        $report = $this->service->submit(
            $this->assignment,
            $this->makeContext(),
            'Kondisi stabil.',
            confirmedPatientLocation: false,
            patientFieldUpdates: [
                'golongan_darah' => 'O',
                'agama' => 'Islam',
                'is_bpjs' => true,
                'no_bpjs' => null, // tidak diisi kader -> tidak boleh ikut tersimpan
            ],
        );

        $this->assertSame(
            ['golongan_darah' => 'O', 'agama' => 'Islam', 'is_bpjs' => true],
            $report->patient_field_updates,
        );
        Queue::assertPushed(SyncFieldUpdateToSilakesJob::class, fn ($job) => $job->visitReportId === $report->id);
        // Tidak ada konfirmasi lokasi -> geo pasien tidak ikut berubah.
        $this->assertSame('approximate', $this->patient->fresh()->geo_status);
    }

    public function test_submit_tanpa_usulan_data_pasien_maupun_konfirmasi_lokasi_tidak_dispatch_job(): void
    {
        Queue::fake();

        $report = $this->service->submit($this->assignment, $this->makeContext(), 'Kondisi stabil.');

        $this->assertNull($report->patient_field_updates);
        Queue::assertNotPushed(SyncFieldUpdateToSilakesJob::class);
    }

    public function test_submit_gagal_validasi_tidak_menyimpan_apa_apa(): void
    {
        Queue::fake();

        // GPS null island -> gagal di layer pertama (gps_active), fail-fast.
        try {
            $this->service->submit($this->assignment, $this->makeContext(['latitude' => 0.0, 'longitude' => 0.0]), 'Kondisi stabil.');
            $this->fail('Seharusnya melempar ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, VisitReport::count());
        $this->assertSame('pending', $this->assignment->fresh()->status);
        Queue::assertNotPushed(SyncFieldUpdateToSilakesJob::class);
    }

    public function test_submit_ditolak_untuk_assignment_yang_sudah_completed(): void
    {
        $this->assignment->update(['status' => 'completed']);

        $this->expectException(ValidationException::class);

        $this->service->submit($this->assignment, $this->makeContext(), 'Kondisi stabil.');
    }

    public function test_submit_tetap_sukses_meski_silakes_down_karena_tidak_pernah_dipanggil_sinkron(): void
    {
        Queue::fake();
        // SiLAKES benar-benar down -> kalaupun job jalan, ini akan gagal. Tapi job TIDAK BOLEH
        // jalan sinkron di dalam submit(), jadi submit() ini tidak boleh pernah tahu soal ini.
        Http::fake(['*' => Http::response(null, 500)]);

        $report = $this->service->submit(
            $this->assignment,
            $this->makeContext(),
            'Kondisi stabil.',
            confirmedPatientLocation: true,
        );

        $this->assertInstanceOf(VisitReport::class, $report);
        Http::assertNothingSent();
        Queue::assertPushed(SyncFieldUpdateToSilakesJob::class);
    }
}
