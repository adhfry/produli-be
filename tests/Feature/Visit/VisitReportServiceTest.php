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
            submitterName: $overrides['submitterName'] ?? 'Bu Siti',
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

    public function test_submit_menotif_admin_puskesmas_dan_pj_prolanis_di_puskesmas_yang_sama(): void
    {
        Queue::fake();
        $this->seed(\Database\Seeders\RolesSeeder::class);

        $puskesmasId = $this->assignment->puskesmas_id_snapshot;

        $admin = User::factory()->create(['puskesmas_id' => $puskesmasId]);
        $admin->assignRole('admin_puskesmas');
        $pj = User::factory()->create(['puskesmas_id' => $puskesmasId]);
        $pj->assignRole('pj_prolanis');
        // Kader di puskesmas yang SAMA -- TIDAK boleh ikut kena notif ini (target cuma
        // admin_puskesmas + pj_prolanis, bukan puskesmas() yang menyasar semua user).
        $otherKaderUser = User::factory()->create(['puskesmas_id' => $puskesmasId]);
        $otherKaderUser->assignRole('kader');
        // Admin di puskesmas LAIN -- tidak boleh ikut kena notif ini juga.
        $otherPuskesmas = Puskesmas::create(['kabupaten_id' => $this->patient->puskesmas->kabupaten_id, 'kode_internal' => 'PKM-B', 'nama' => 'Puskesmas B']);
        $otherPuskesmasAdmin = User::factory()->create(['puskesmas_id' => $otherPuskesmas->id]);
        $otherPuskesmasAdmin->assignRole('admin_puskesmas');

        $this->service->submit($this->assignment, $this->makeContext(), 'Kondisi stabil.');

        Queue::assertPushed(\App\Jobs\DispatchNotifyPayloadJob::class, function ($job) use ($admin) {
            return $job->userId === $admin->id
                && $job->payload->type === 'visit_report_submitted'
                && $job->payload->data['severity'] === 'danger'
                && $job->payload->data['action_url'] === "/dashboard/kunjungan?assignment_id={$this->assignment->id}"
                // Permintaan eksplisit user -- tombol "Lihat Laporan" (bukan lagi "Lihat Kunjungan"),
                // dan imageUrl (foto bukti lapangan) diteruskan dari VisitReport::photoUrl() ke FCM
                // notification.image (lihat FcmReminderChannel/FcmService). null di sini WAJAR --
                // storage disk tidak dikonfigurasi di test env (photoUrl() graceful null, bukan bug),
                // yang penting key-nya benar-benar dibaca dari payload objek, bukan hilang di jalan.
                && $job->payload->data['action_label'] === 'Lihat Laporan'
                && array_key_exists('imageUrl', get_object_vars($job->payload))
                && in_array('fcm', $job->channelKeys, true);
        });
        Queue::assertPushed(\App\Jobs\DispatchNotifyPayloadJob::class, function ($job) use ($pj) {
            return $job->userId === $pj->id;
        });
        Queue::assertNotPushed(\App\Jobs\DispatchNotifyPayloadJob::class, function ($job) use ($otherKaderUser) {
            return $job->userId === $otherKaderUser->id;
        });
        Queue::assertNotPushed(\App\Jobs\DispatchNotifyPayloadJob::class, function ($job) use ($otherPuskesmasAdmin) {
            return $job->userId === $otherPuskesmasAdmin->id;
        });
    }

    /**
     * Verifikasi eksplisit perbaikan targeting: notifikasi HARUS ikut puskesmas KADER pelapor,
     * bukan puskesmas_id_snapshot assignment (yang turunan puskesmas PASIEN). Skenario ini
     * sengaja membuat keduanya BEDA -- sebelum perbaikan, test ini akan gagal karena notif salah
     * sasaran ke admin puskesmas pasien, bukan admin puskesmas kader.
     */
    public function test_submit_menotif_berdasar_puskesmas_kader_bukan_puskesmas_snapshot_pasien(): void
    {
        Queue::fake();
        $this->seed(\Database\Seeders\RolesSeeder::class);

        $kabupaten = $this->patient->puskesmas->kabupaten;
        $puskesmasKader = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-KADER', 'nama' => 'Puskesmas Kader']);
        $puskesmasPasien = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-PASIEN', 'nama' => 'Puskesmas Pasien']);

        $this->kader->update(['puskesmas_id' => $puskesmasKader->id]);
        $this->patient->update(['puskesmas_id' => $puskesmasPasien->id]);
        $this->assignment->update(['puskesmas_id_snapshot' => $puskesmasPasien->id]);

        $adminKader = User::factory()->create(['puskesmas_id' => $puskesmasKader->id]);
        $adminKader->assignRole('admin_puskesmas');
        $adminPasien = User::factory()->create(['puskesmas_id' => $puskesmasPasien->id]);
        $adminPasien->assignRole('admin_puskesmas');

        $this->service->submit($this->assignment->fresh(), $this->makeContext(), 'Kondisi stabil.');

        Queue::assertPushed(\App\Jobs\DispatchNotifyPayloadJob::class, fn ($job) => $job->userId === $adminKader->id);
        Queue::assertNotPushed(\App\Jobs\DispatchNotifyPayloadJob::class, fn ($job) => $job->userId === $adminPasien->id);
    }

    public function test_submit_dengan_tindakan_array_tersimpan_apa_adanya(): void
    {
        Queue::fake();

        $report = $this->service->submit(
            $this->assignment,
            $this->makeContext(),
            'Kondisi stabil.',
            pemeriksaan: ['tindakan' => ['diberi_obat', 'tidak_ada']],
        );

        $this->assertSame(['diberi_obat', 'tidak_ada'], $report->fresh()->tindakan);
        $this->assertNull($report->fresh()->rujukan_status);
    }

    /**
     * Fase 2 -- 'dirujuk_puskesmas' di antara tindakan (bisa dipilih bareng tindakan lain
     * sekaligus) otomatis set rujukan_status='menunggu_konfirmasi' DAN memicu notifikasi
     * KHUSUS 'pasien_dirujuk' (3 kanal: push+fcm+email, beda dari visit_report_submitted yang
     * cuma 2 kanal) -- lihat VisitReportService::notifyPasienDirujuk().
     */
    public function test_submit_dengan_dirujuk_puskesmas_set_rujukan_status_dan_notif_3_kanal(): void
    {
        Queue::fake();
        $this->seed(\Database\Seeders\RolesSeeder::class);

        $puskesmasId = $this->kader->puskesmas_id;
        $admin = User::factory()->create(['puskesmas_id' => $puskesmasId]);
        $admin->assignRole('admin_puskesmas');

        $report = $this->service->submit(
            $this->assignment,
            $this->makeContext(),
            'Kondisi stabil.',
            pemeriksaan: ['tindakan' => ['diberi_obat', 'dirujuk_puskesmas'], 'cara_rujukan' => 'dijemput_ambulan'],
        );

        $this->assertSame('menunggu_konfirmasi', $report->fresh()->rujukan_status);
        $this->assertSame('dijemput_ambulan', $report->fresh()->cara_rujukan);

        Queue::assertPushed(\App\Jobs\DispatchNotifyPayloadJob::class, function ($job) use ($admin, $report) {
            return $job->userId === $admin->id
                && $job->payload->type === 'pasien_dirujuk'
                && $job->payload->data['visit_report_id'] === $report->id
                && in_array('email', $job->channelKeys, true)
                && in_array('fcm', $job->channelKeys, true);
        });
    }

    public function test_submit_tanpa_dirujuk_puskesmas_tidak_set_rujukan_status_maupun_notif_pasien_dirujuk(): void
    {
        Queue::fake();
        $this->seed(\Database\Seeders\RolesSeeder::class);

        $admin = User::factory()->create(['puskesmas_id' => $this->kader->puskesmas_id]);
        $admin->assignRole('admin_puskesmas');

        $report = $this->service->submit(
            $this->assignment,
            $this->makeContext(),
            'Kondisi stabil.',
            pemeriksaan: ['tindakan' => ['diberi_obat']],
        );

        $this->assertNull($report->fresh()->rujukan_status);
        Queue::assertNotPushed(\App\Jobs\DispatchNotifyPayloadJob::class, fn ($job) => $job->payload->type === 'pasien_dirujuk');
    }

    public function test_submit_tetap_sukses_walau_role_belum_ter_seed(): void
    {
        // TIDAK seed RolesSeeder -- NotifyService::resolveUserIds() akan throw RoleDoesNotExist
        // saat resolve target admin_puskesmas/pj_prolanis. Laporan (yang jauh lebih penting)
        // tetap harus tersimpan -- lihat docblock VisitReportService::notifyReportSubmitted().
        Queue::fake();

        $report = $this->service->submit($this->assignment, $this->makeContext(), 'Kondisi stabil.');

        $this->assertInstanceOf(VisitReport::class, $report);
        $this->assertSame('completed', $this->assignment->fresh()->status);
    }

    /**
     * Regresi temuan audit (docs/planning/15) -- retry offline dengan client_submission_id yang
     * SAMA (mis. antrean IndexedDB kader mengirim ulang draft yang sama karena request pertama
     * timeout tapi sebenarnya sudah tersimpan) TIDAK boleh menghasilkan VisitReport kedua,
     * assignment TIDAK boleh gagal karena sudah 'completed', dan efek samping (notifikasi, job
     * sync SiLAKES) TIDAK boleh terpicu dua kali.
     */
    public function test_submit_retry_dengan_client_submission_id_sama_idempotent(): void
    {
        Queue::fake();

        $context = $this->makeContext(['isOffline' => true, 'clientSubmissionId' => 'draft-uuid-abc123']);

        $first = $this->service->submit($this->assignment, $context, 'Kondisi stabil.', confirmedPatientLocation: true);
        Queue::assertPushed(SyncFieldUpdateToSilakesJob::class, 1);

        // $this->assignment masih instance LAMA (status 'pending' di memori) -- persis kondisi
        // nyata saat retry: assignment yang dipakai ulang belum tentu di-refresh, sengaja TIDAK
        // di-fresh() di sini supaya test benar-benar menguji jalur "assignment terlihat pending
        // padahal sudah completed di DB".
        $retry = $this->service->submit($this->assignment, $context, 'Kondisi stabil.', confirmedPatientLocation: true);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, VisitReport::count());
        Queue::assertPushed(SyncFieldUpdateToSilakesJob::class, 1);
    }

    public function test_submit_online_biasa_tanpa_client_submission_id_tetap_berhasil(): void
    {
        // Jalur ONLINE (bukan offline) TIDAK pernah mengirim client_submission_id sama sekali
        // (lihat buildOnlineFormData() di frontend) -- pastikan null di sini tidak memicu
        // pengecekan idempotensi/unique constraint yang salah.
        Queue::fake();

        $report = $this->service->submit($this->assignment, $this->makeContext(['isOffline' => false, 'clientSubmissionId' => null]), 'Kondisi stabil.');

        $this->assertInstanceOf(VisitReport::class, $report);
        $this->assertNull($report->client_submission_id);
    }
}
