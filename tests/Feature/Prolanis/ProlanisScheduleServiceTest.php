<?php

namespace Tests\Feature\Prolanis;

use App\Models\Kabupaten;
use App\Models\LabResultCache;
use App\Models\PatientsCache;
use App\Models\ProlanisSchedule;
use App\Models\Puskesmas;
use App\Models\User;
use App\Services\Prolanis\ProlanisScheduleService;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Regresi untuk ProlanisScheduleService (permintaan user, penjadwalan otomatis kegiatan
 * Prolanis) -- jadwal dihitung dari lab_results_cache.tanggal_periksa TERBARU, BUKAN created_at,
 * + interval sesuai jenis_prolanis (DM 3 bulan, HT 6 bulan).
 */
class ProlanisScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProlanisScheduleService $service;

    private Puskesmas $puskesmas;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('produli.prolanis_schedule.dm_interval_months', 3);
        Config::set('produli.prolanis_schedule.ht_interval_months', 6);
        Config::set('produli.prolanis_schedule.reminder_days_before', 7);

        $this->seed(RolesSeeder::class);

        $this->service = app(ProlanisScheduleService::class);

        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $this->puskesmas = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-A', 'nama' => 'Puskesmas A']);
    }

    private function makePatient(string $jenisProlanis, int $externalId = 1): PatientsCache
    {
        return PatientsCache::create([
            'external_patient_id' => $externalId,
            'nik_hash' => 'HASH-'.$externalId,
            'nama' => 'Pasien '.$externalId,
            'is_prolanis' => true,
            'jenis_prolanis' => $jenisProlanis,
            'puskesmas_id' => $this->puskesmas->id,
            'wilayah_status' => 'unknown',
        ]);
    }

    public function test_generate_pasien_dm_jadwal_3_bulan_dari_tanggal_lab_terbaru(): void
    {
        $patient = $this->makePatient('DM');
        LabResultCache::create(['external_id' => 1, 'patient_id' => $patient->external_patient_id, 'parameter' => 'Gula Darah Puasa', 'value' => '90', 'tanggal_periksa' => '2026-05-08', 'synced_at' => '2026-05-10']);

        $this->service->generateSchedules();

        $schedule = ProlanisSchedule::where('patient_id', $patient->id)->first();
        $this->assertNotNull($schedule);
        $this->assertSame('2026-05-08', $schedule->source_lab_date->toDateString());
        $this->assertSame('2026-08-08', $schedule->scheduled_date->toDateString());
        $this->assertSame('terjadwal', $schedule->status);
        $this->assertFalse($schedule->is_manual_override);
    }

    public function test_generate_pasien_ht_jadwal_6_bulan(): void
    {
        $patient = $this->makePatient('HT');
        LabResultCache::create(['external_id' => 1, 'patient_id' => $patient->external_patient_id, 'parameter' => 'Cholesterol', 'value' => '180', 'tanggal_periksa' => '2026-05-08', 'synced_at' => '2026-05-10']);

        $this->service->generateSchedules();

        $schedule = ProlanisSchedule::where('patient_id', $patient->id)->first();
        $this->assertSame('2026-11-08', $schedule->scheduled_date->toDateString());
    }

    public function test_generate_memakai_tanggal_periksa_lab_terbaru_bukan_created_at(): void
    {
        // Permintaan user eksplisit: lihat field tanggal_periksa, BUKAN created_at (kapan baris
        // masuk ke cache PRODULI -- bisa jauh belakangan dari tanggal lab sesungguhnya).
        $patient = $this->makePatient('DM');
        LabResultCache::create(['external_id' => 1, 'patient_id' => $patient->external_patient_id, 'parameter' => 'Cholesterol', 'value' => '180', 'tanggal_periksa' => '2026-05-08', 'synced_at' => '2026-05-10']);
        // created_at "hari ini" (default factory/model timestamp) TAPI tanggal_periksa lab lebih
        // baru -- yang dipakai HARUS tanggal_periksa yang lebih baru ini (2026-06-15), bukan
        // Mei, dan BUKAN pula "hari ini" dari created_at.
        LabResultCache::create(['external_id' => 2, 'patient_id' => $patient->external_patient_id, 'parameter' => 'Trigliserida', 'value' => '150', 'tanggal_periksa' => '2026-06-15', 'synced_at' => '2026-06-16']);

        $this->service->generateSchedules();

        $schedule = ProlanisSchedule::where('patient_id', $patient->id)->first();
        $this->assertSame('2026-06-15', $schedule->source_lab_date->toDateString());
        $this->assertSame('2026-09-15', $schedule->scheduled_date->toDateString());
    }

    public function test_generate_tidak_menimpa_jadwal_yang_sudah_manual_override(): void
    {
        $patient = $this->makePatient('DM');
        LabResultCache::create(['external_id' => 1, 'patient_id' => $patient->external_patient_id, 'parameter' => 'Cholesterol', 'value' => '180', 'tanggal_periksa' => '2026-05-08', 'synced_at' => '2026-05-10']);

        ProlanisSchedule::create([
            'patient_id' => $patient->id, 'puskesmas_id' => $this->puskesmas->id, 'jenis_prolanis' => 'DM',
            'source_lab_date' => '2026-05-08', 'scheduled_date' => '2026-12-25', 'is_manual_override' => true,
        ]);

        $this->service->generateSchedules();

        $this->assertSame('2026-12-25', ProlanisSchedule::where('patient_id', $patient->id)->first()->scheduled_date->toDateString());
    }

    public function test_generate_idempotent_tidak_menulis_ulang_tanpa_lab_baru(): void
    {
        $patient = $this->makePatient('DM');
        LabResultCache::create(['external_id' => 1, 'patient_id' => $patient->external_patient_id, 'parameter' => 'Cholesterol', 'value' => '180', 'tanggal_periksa' => '2026-05-08', 'synced_at' => '2026-05-10']);

        $this->service->generateSchedules();
        $firstUpdatedAt = ProlanisSchedule::where('patient_id', $patient->id)->first()->updated_at;

        sleep(1);
        $this->service->generateSchedules();

        $this->assertTrue($firstUpdatedAt->equalTo(ProlanisSchedule::where('patient_id', $patient->id)->first()->updated_at));
    }

    public function test_generate_lab_baru_membuat_jadwal_baru_dan_reset_notified_at(): void
    {
        $patient = $this->makePatient('DM');
        LabResultCache::create(['external_id' => 1, 'patient_id' => $patient->external_patient_id, 'parameter' => 'Cholesterol', 'value' => '180', 'tanggal_periksa' => '2026-05-08', 'synced_at' => '2026-05-10']);
        $this->service->generateSchedules();
        ProlanisSchedule::where('patient_id', $patient->id)->update(['notified_at' => now()]);

        // Pasien diperiksa lagi lebih awal dari jadwal (lab baru masuk).
        LabResultCache::create(['external_id' => 2, 'patient_id' => $patient->external_patient_id, 'parameter' => 'Trigliserida', 'value' => '150', 'tanggal_periksa' => '2026-06-01', 'synced_at' => '2026-06-02']);
        $this->service->generateSchedules();

        $schedule = ProlanisSchedule::where('patient_id', $patient->id)->first();
        $this->assertSame('2026-06-01', $schedule->source_lab_date->toDateString());
        $this->assertSame('2026-09-01', $schedule->scheduled_date->toDateString());
        $this->assertNull($schedule->notified_at);
    }

    public function test_pasien_tanpa_hasil_lab_tidak_dijadwalkan(): void
    {
        $this->makePatient('DM');

        $this->service->generateSchedules();

        $this->assertSame(0, ProlanisSchedule::count());
    }

    public function test_reschedule_menandai_manual_override(): void
    {
        $patient = $this->makePatient('DM');
        $admin = User::factory()->create(['puskesmas_id' => $this->puskesmas->id]);
        $schedule = ProlanisSchedule::create([
            'patient_id' => $patient->id, 'puskesmas_id' => $this->puskesmas->id, 'jenis_prolanis' => 'DM',
            'scheduled_date' => '2026-08-08',
        ]);

        $this->service->reschedule($schedule, '2026-08-20', $admin);

        $fresh = $schedule->fresh();
        $this->assertSame('2026-08-20', $fresh->scheduled_date->toDateString());
        $this->assertTrue($fresh->is_manual_override);
        $this->assertSame($admin->id, $fresh->updated_by);
    }

    public function test_send_due_reminders_kirim_1_notifikasi_per_puskesmas(): void
    {
        Notification::fake();
        Config::set('produli.prolanis_schedule.reminder_days_before', 7);

        $admin = User::factory()->create(['puskesmas_id' => $this->puskesmas->id]);
        $admin->assignRole('admin_puskesmas');

        $patientA = $this->makePatient('DM', 1);
        $patientB = $this->makePatient('DM', 2);
        $targetDate = now()->addDays(7)->toDateString();

        ProlanisSchedule::create(['patient_id' => $patientA->id, 'puskesmas_id' => $this->puskesmas->id, 'jenis_prolanis' => 'DM', 'scheduled_date' => $targetDate, 'status' => 'terjadwal']);
        ProlanisSchedule::create(['patient_id' => $patientB->id, 'puskesmas_id' => $this->puskesmas->id, 'jenis_prolanis' => 'DM', 'scheduled_date' => $targetDate, 'status' => 'terjadwal']);
        // Di luar jendela H-1 minggu -- tidak boleh ikut.
        $patientC = $this->makePatient('DM', 3);
        ProlanisSchedule::create(['patient_id' => $patientC->id, 'puskesmas_id' => $this->puskesmas->id, 'jenis_prolanis' => 'DM', 'scheduled_date' => now()->addDays(20)->toDateString(), 'status' => 'terjadwal']);

        $notifiedPuskesmas = $this->service->sendDueReminders();

        $this->assertSame(1, $notifiedPuskesmas);
        Notification::assertSentTo(
            $admin,
            \App\Notifications\GenericDatabaseNotification::class,
            fn ($n) => $n->toDatabase($admin)['type'] === 'prolanis_schedule_reminder' && $n->toDatabase($admin)['count'] === 2
        );
        $this->assertNotNull(ProlanisSchedule::where('patient_id', $patientA->id)->first()->notified_at);
        $this->assertNull(ProlanisSchedule::where('patient_id', $patientC->id)->first()->notified_at);
    }

    public function test_send_due_reminders_tidak_kirim_ulang_yang_sudah_dinotif(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['puskesmas_id' => $this->puskesmas->id]);
        $admin->assignRole('admin_puskesmas');
        $patient = $this->makePatient('DM');
        ProlanisSchedule::create([
            'patient_id' => $patient->id, 'puskesmas_id' => $this->puskesmas->id, 'jenis_prolanis' => 'DM',
            'scheduled_date' => now()->addDays(7)->toDateString(), 'status' => 'terjadwal', 'notified_at' => now(),
        ]);

        $notifiedPuskesmas = $this->service->sendDueReminders();

        $this->assertSame(0, $notifiedPuskesmas);
    }
}
