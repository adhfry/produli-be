<?php

namespace Tests\Feature\Jobs;

use App\Jobs\SendVisitReminderJob;
use App\Models\Kabupaten;
use App\Models\Kader;
use App\Models\PatientsCache;
use App\Models\Puskesmas;
use App\Models\Reminder;
use App\Models\User;
use App\Models\VisitAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Regresi untuk SendVisitReminderJob (docs/planning/02 §8) -- dispatch lewat queue sungguhan
 * (bukan panggil handle() langsung, bukan Queue::fake()) supaya failed() callback tersambung
 * ke mesin queue Laravel, sama seperti pola di SyncFieldUpdateToSilakesJobTest.
 */
class SendVisitReminderJobTest extends TestCase
{
    use RefreshDatabase;

    private Reminder $reminder;

    protected function setUp(): void
    {
        parent::setUp();

        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $puskesmas = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-A', 'nama' => 'Puskesmas A']);
        $kaderUser = User::factory()->create(['name' => 'Bu Siti']);
        $kader = Kader::create(['user_id' => $kaderUser->id, 'puskesmas_id' => $puskesmas->id, 'status_aktif' => true]);

        $patient = PatientsCache::create([
            'external_patient_id' => 980001,
            'nik_hash' => 'HASH-980001',
            'nama' => 'Pasien Uji Job',
            'wilayah_status' => 'unknown',
        ]);

        $assignment = VisitAssignment::create([
            'patient_id' => $patient->id,
            'kader_id' => $kader->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'pending',
            'priority' => 'sedang',
            'puskesmas_id_snapshot' => $puskesmas->id,
        ]);

        $this->reminder = Reminder::create([
            'assignment_id' => $assignment->id,
            'channel' => 'push',
            'scheduled_at' => now()->subMinute(),
            'status' => 'pending',
        ]);
    }

    public function test_sukses_menandai_sent_dan_membuat_notifikasi(): void
    {
        SendVisitReminderJob::dispatch($this->reminder->id);

        $fresh = $this->reminder->fresh();
        $this->assertSame('sent', $fresh->status);
        $this->assertNotNull($fresh->sent_at);
        $this->assertSame(1, $fresh->assignment->kader->user->notifications()->count());
    }

    public function test_channel_tidak_dikenal_menandai_failed_lewat_failed_callback(): void
    {
        // 'sms' sengaja dipakai (bukan 'wa') -- 'wa' sekarang channel SUNGGUHAN
        // (WhatsappReminderChannel, revisi Bu Kadis), 'sms' tetap tidak terdaftar sama sekali.
        $this->reminder->update(['channel' => 'sms']);

        try {
            SendVisitReminderJob::dispatch($this->reminder->id);
            $this->fail('Seharusnya melempar RuntimeException.');
        } catch (RuntimeException) {
            // expected -- SyncQueue (test env) melempar ulang exception job ke pemanggil.
        }

        $fresh = $this->reminder->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertStringContainsString('sms', $fresh->error_message);
    }

    public function test_tries_dan_backoff_terkonfigurasi(): void
    {
        $job = new SendVisitReminderJob($this->reminder->id);

        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300, 900], $job->backoff());
    }
}
