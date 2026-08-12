<?php

namespace App\Services\Notification;

use App\Jobs\SendVisitReminderJob;
use App\Models\Reminder;
use App\Models\VisitAssignment;
use App\Models\VisitReport;
use App\Notifications\VisitReportInvalidatedNotification;
use App\Services\Notification\Channels\DatabaseReminderChannel;
use App\Services\Notification\Channels\FcmReminderChannel;
use App\Services\Notification\Channels\WhatsappReminderChannel;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Penjadwalan & pengiriman notifikasi ke kader/tenaga_kesehatan (docs/planning/02 §8/§11). Dua
 * tanggung jawab:
 *
 * 1. Reminder kunjungan -- dipanggil command produli:send-visit-reminders (routes/console.php,
 *    twiceDaily): scheduleUpcomingReminders() buat baris `reminders` utk assignment H-1/hari-H
 *    (idempotent, dedup lewat unique index assignment_id+channel+scheduled_at di migration),
 *    dispatchDueReminders() enqueue SendVisitReminderJob utk reminder yang sudah waktunya.
 * 2. Notifikasi ad-hoc lain (mis. notifyReportInvalidated()) -- dikirim langsung lewat
 *    Notification::send(), bukan lewat tabel `reminders` (itu KHUSUS reminder terjadwal).
 *
 * TERPISAH dari NotifyService (revisi Bu Kadis, fan-out event umum: sync selesai/update
 * pasien/kunjungan mendesak) -- class ini tetap scope sempit ke mekanisme reminder H-1/hari-H
 * berbasis tabel `reminders`, lihat docblock NotifyService untuk alasan tidak digabung.
 */
class NotificationService
{
    /** @var array<string, ReminderChannel> */
    private readonly array $channels;

    public function __construct(
        DatabaseReminderChannel $databaseReminderChannel,
        WhatsappReminderChannel $whatsappReminderChannel,
        FcmReminderChannel $fcmReminderChannel,
    ) {
        // WA TERDAFTAR tapi SENGAJA TIDAK DIPAKAI aktif di manapun untuk saat ini (bot WA milik
        // user masih dalam pembuatan) -- channelsFor() di bawah cuma emit 'push'+'fcm'. Kode WA
        // (WhatsappReminderChannel) dibiarkan siap pakai, tinggal ganti channelsFor() kalau bot-nya
        // sudah jadi, TIDAK perlu bongkar arsitektur lagi.
        $this->channels = [
            $databaseReminderChannel->key() => $databaseReminderChannel,
            $whatsappReminderChannel->key() => $whatsappReminderChannel,
            $fcmReminderChannel->key() => $fcmReminderChannel,
        ];
    }

    public function scheduleUpcomingReminders(): int
    {
        $today = Carbon::today();
        $tomorrow = $today->copy()->addDay();

        // whereDate() (bukan whereIn raw) -- kolom scheduled_date di-cast 'date' tapi disimpan
        // lewat format datetime penuh grammar koneksi ('Y-m-d H:i:s'); whereDate() menormalkan
        // perbandingan di level SQL supaya benar di semua driver (termasuk SQLite test yang
        // menyimpan apa adanya, beda dari kolom DATE asli MySQL yang otomatis memangkas jam).
        $assignments = VisitAssignment::where('status', 'pending')
            ->where(function ($query) use ($today, $tomorrow) {
                $query->whereDate('scheduled_date', $today)
                    ->orWhereDate('scheduled_date', $tomorrow);
            })
            ->get();

        $created = 0;

        foreach ($assignments as $assignment) {
            foreach ($this->targetTimesFor($assignment, $today, $tomorrow) as $scheduledAt) {
                foreach ($this->channelsFor($assignment) as $channel) {
                    $exists = Reminder::where('assignment_id', $assignment->id)
                        ->where('channel', $channel)
                        ->where('scheduled_at', $scheduledAt)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    Reminder::create([
                        'assignment_id' => $assignment->id,
                        'channel' => $channel,
                        'scheduled_at' => $scheduledAt,
                        'status' => 'pending',
                    ]);

                    $created++;
                }
            }
        }

        return $created;
    }

    /**
     * Assignment satu-kali biasa (visit_origin='manual', care_assignment_id=NULL) TETAP HANYA
     * 'push' seperti sebelum revisi Bu Kadis -- gate ini WAJIB supaya
     * NotificationServiceTest::test_schedule_upcoming_reminders_membuat_reminder_h_minus_1_dan_hari_h
     * (fixture manual, assert Reminder::count()===2) tidak ikut berubah jadi 4. Assignment hasil
     * kadensi berulang (care_assignment_id terisi) dapat 'fcm' juga -- kader/tenaga_kesehatan
     * dapat pengingat push browser untuk tugas berulang mereka. 'wa' SENGAJA belum dipakai di
     * sini (bot WA user masih dalam pembuatan) -- tinggal tambah 'wa' ke array ini nanti begitu
     * kredensialnya siap, channel-nya sendiri sudah terdaftar & siap pakai (lihat constructor).
     *
     * @return array<int, string>
     */
    private function channelsFor(VisitAssignment $assignment): array
    {
        return $assignment->care_assignment_id !== null ? ['push', 'fcm'] : ['push'];
    }

    /**
     * @return Carbon[]
     */
    private function targetTimesFor(VisitAssignment $assignment, Carbon $today, Carbon $tomorrow): array
    {
        $targets = [];

        if ($assignment->scheduled_date->isSameDay($tomorrow)) {
            $targets[] = $assignment->scheduled_date->copy()->subDay()
                ->setTimeFromTimeString((string) config('produli.reminders.h_minus_1_time'));
        }

        if ($assignment->scheduled_date->isSameDay($today)) {
            $targets[] = $assignment->scheduled_date->copy()
                ->setTimeFromTimeString((string) config('produli.reminders.same_day_time'));
        }

        return $targets;
    }

    public function dispatchDueReminders(): int
    {
        $reminderIds = Reminder::where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->pluck('id');

        foreach ($reminderIds as $reminderId) {
            SendVisitReminderJob::dispatch($reminderId);
        }

        return $reminderIds->count();
    }

    /**
     * Dipanggil dari SendVisitReminderJob -- kirim SATU reminder yang sudah waktunya.
     */
    public function deliver(int $reminderId): void
    {
        $reminder = Reminder::with(['assignment.kader.user', 'assignment.tenagaKesehatan.user', 'assignment.patient'])->find($reminderId);

        if (! $reminder || $reminder->status !== 'pending') {
            return; // sudah dihapus / sudah diproses (guard idempotency)
        }

        $assignment = $reminder->assignment;

        if ($assignment->status !== 'pending') {
            // Petugas sudah menyelesaikan kunjungan / assignment dibatalkan sebelum reminder
            // sempat terkirim -- bukan kegagalan, cuma tidak relevan lagi.
            $reminder->update(['status' => 'skipped']);

            return;
        }

        $channel = $this->channels[$reminder->channel] ?? null;

        if (! $channel) {
            throw new RuntimeException("Channel reminder '{$reminder->channel}' tidak dikenal.");
        }

        $notifiable = $assignment->assigneeUser();

        if ($notifiable === null) {
            $reminder->update(['status' => 'skipped']);

            return;
        }

        $channel->send($notifiable, new NotificationPayload(
            type: 'visit_reminder',
            title: 'Pengingat Kunjungan',
            body: "Kunjungi {$assignment->patient->nama} pada {$assignment->scheduled_date->toDateString()}.",
            data: [
                'type' => 'visit_reminder',
                'assignment_id' => $assignment->id,
                'patient_nama' => $assignment->patient->nama,
                'scheduled_date' => $assignment->scheduled_date->toDateString(),
                'priority' => $assignment->priority,
            ],
        ));

        $reminder->update(['status' => 'sent', 'sent_at' => now()]);
    }

    /**
     * Beri tahu kader kalau laporan kunjungannya dinyatakan TIDAK VALID oleh super_admin
     * (docs/planning/02 §11), berisi validation_note supaya tahu alasannya dan bisa kunjungan
     * ulang dengan benar. Dipanggil dari VisitReportReviewService::validateReport() SETELAH
     * assignment terkait sudah dikembalikan ke status pending.
     */
    public function notifyReportInvalidated(VisitReport $report): void
    {
        $kader = $report->assignment?->kader;

        if ($kader === null || $kader->user === null) {
            return;
        }

        Notification::send($kader->user, new VisitReportInvalidatedNotification($report));
    }
}
