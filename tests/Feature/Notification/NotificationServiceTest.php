<?php

namespace Tests\Feature\Notification;

use App\Models\Kabupaten;
use App\Models\Kader;
use App\Models\PatientsCache;
use App\Models\Puskesmas;
use App\Models\Reminder;
use App\Models\User;
use App\Models\VisitAssignment;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Regresi untuk NotificationService (docs/planning/02 §8) -- penjadwalan reminder H-1/hari-H
 * untuk assignment kunjungan kader, dan pengiriman reminder yang sudah waktunya.
 */
class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $service;

    private Kader $kader;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('kopipu.reminders.h_minus_1_time', '16:00');
        Config::set('kopipu.reminders.same_day_time', '06:00');

        $this->service = app(NotificationService::class);

        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $puskesmas = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-A', 'nama' => 'Puskesmas A']);
        $kaderUser = User::factory()->create(['name' => 'Bu Siti']);
        $this->kader = Kader::create(['user_id' => $kaderUser->id, 'puskesmas_id' => $puskesmas->id, 'status_aktif' => true]);
    }

    private function makeAssignment(string $scheduledDate, array $overrides = []): VisitAssignment
    {
        static $externalId = 0;
        $externalId++;

        $patient = PatientsCache::create([
            'external_patient_id' => 970000 + $externalId,
            'nik_hash' => 'HASH-'.$externalId,
            'nama' => 'Pasien '.$externalId,
            'wilayah_status' => 'unknown',
        ]);

        return VisitAssignment::create(array_merge([
            'patient_id' => $patient->id,
            'kader_id' => $this->kader->id,
            'scheduled_date' => $scheduledDate,
            'status' => 'pending',
            'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->kader->puskesmas_id,
        ], $overrides));
    }

    public function test_schedule_upcoming_reminders_membuat_reminder_h_minus_1_dan_hari_h(): void
    {
        $besok = $this->makeAssignment(now()->addDay()->toDateString());
        $hariIni = $this->makeAssignment(now()->toDateString());

        $created = $this->service->scheduleUpcomingReminders();

        $this->assertSame(2, $created);
        $this->assertSame(2, Reminder::count());

        $reminderBesok = Reminder::where('assignment_id', $besok->id)->first();
        $this->assertSame('16:00:00', $reminderBesok->scheduled_at->format('H:i:s'));
        $this->assertTrue($reminderBesok->scheduled_at->isSameDay(now()));

        $reminderHariIni = Reminder::where('assignment_id', $hariIni->id)->first();
        $this->assertSame('06:00:00', $reminderHariIni->scheduled_at->format('H:i:s'));
        $this->assertTrue($reminderHariIni->scheduled_at->isSameDay(now()));
    }

    public function test_schedule_upcoming_reminders_idempotent_tidak_duplikat(): void
    {
        $this->makeAssignment(now()->toDateString());

        $this->service->scheduleUpcomingReminders();
        $firstCount = Reminder::count();

        $createdKedua = $this->service->scheduleUpcomingReminders();

        $this->assertSame(0, $createdKedua);
        $this->assertSame($firstCount, Reminder::count());
    }

    public function test_schedule_upcoming_reminders_lewati_assignment_yang_bukan_pending(): void
    {
        $this->makeAssignment(now()->toDateString(), ['status' => 'completed']);

        $created = $this->service->scheduleUpcomingReminders();

        $this->assertSame(0, $created);
        $this->assertSame(0, Reminder::count());
    }

    public function test_schedule_upcoming_reminders_lewati_assignment_diluar_jendela_h1_hari_h(): void
    {
        $this->makeAssignment(now()->addDays(5)->toDateString());

        $created = $this->service->scheduleUpcomingReminders();

        $this->assertSame(0, $created);
        $this->assertSame(0, Reminder::count());
    }

    public function test_dispatch_due_reminders_hanya_dispatch_yang_sudah_waktunya(): void
    {
        Queue::fake();

        $assignment = $this->makeAssignment(now()->toDateString());
        $due = Reminder::create([
            'assignment_id' => $assignment->id,
            'channel' => 'push',
            'scheduled_at' => now()->subMinute(),
            'status' => 'pending',
        ]);
        $belumWaktunya = Reminder::create([
            'assignment_id' => $assignment->id,
            'channel' => 'push',
            'scheduled_at' => now()->addHour(),
            'status' => 'pending',
        ]);

        $dispatched = $this->service->dispatchDueReminders();

        $this->assertSame(1, $dispatched);
        Queue::assertPushed(\App\Jobs\SendVisitReminderJob::class, fn ($job) => $job->reminderId === $due->id);
        Queue::assertNotPushed(\App\Jobs\SendVisitReminderJob::class, fn ($job) => $job->reminderId === $belumWaktunya->id);
    }

    public function test_deliver_mengirim_notifikasi_dan_menandai_sent(): void
    {
        $assignment = $this->makeAssignment(now()->toDateString());
        $reminder = Reminder::create([
            'assignment_id' => $assignment->id,
            'channel' => 'push',
            'scheduled_at' => now()->subMinute(),
            'status' => 'pending',
        ]);

        $this->service->deliver($reminder->id);

        $fresh = $reminder->fresh();
        $this->assertSame('sent', $fresh->status);
        $this->assertNotNull($fresh->sent_at);

        $this->assertSame(1, $this->kader->user->notifications()->count());
        $notification = $this->kader->user->notifications()->first();
        $this->assertSame($assignment->id, $notification->data['assignment_id']);
    }

    public function test_deliver_skip_kalau_assignment_sudah_tidak_pending(): void
    {
        $assignment = $this->makeAssignment(now()->toDateString(), ['status' => 'completed']);
        $reminder = Reminder::create([
            'assignment_id' => $assignment->id,
            'channel' => 'push',
            'scheduled_at' => now()->subMinute(),
            'status' => 'pending',
        ]);

        $this->service->deliver($reminder->id);

        $this->assertSame('skipped', $reminder->fresh()->status);
        $this->assertSame(0, $this->kader->user->notifications()->count());
    }

    public function test_deliver_guard_reminder_yang_sudah_diproses_tidak_dikirim_ulang(): void
    {
        $assignment = $this->makeAssignment(now()->toDateString());
        $reminder = Reminder::create([
            'assignment_id' => $assignment->id,
            'channel' => 'push',
            'scheduled_at' => now()->subMinute(),
            'status' => 'sent',
            'sent_at' => now()->subSeconds(30),
        ]);

        $this->service->deliver($reminder->id);

        $this->assertSame(0, $this->kader->user->notifications()->count());
    }

    public function test_deliver_reminder_tidak_ada_tidak_error(): void
    {
        $this->service->deliver(999999);

        $this->assertSame(0, \App\Models\Reminder::count());
    }

    public function test_deliver_channel_tidak_dikenal_melempar_exception(): void
    {
        $assignment = $this->makeAssignment(now()->toDateString());
        $reminder = Reminder::create([
            'assignment_id' => $assignment->id,
            'channel' => 'wa',
            'scheduled_at' => now()->subMinute(),
            'status' => 'pending',
        ]);

        $this->expectException(RuntimeException::class);

        $this->service->deliver($reminder->id);
    }
}
