<?php

namespace App\Services\Notification;

use App\Jobs\SendVisitReminderJob;
use App\Models\Reminder;
use App\Models\VisitAssignment;
use App\Models\VisitReport;
use App\Services\Notification\Channels\DatabaseReminderChannel;
use App\Services\Notification\Channels\FcmReminderChannel;
use App\Services\Notification\Channels\WhatsappReminderChannel;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

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
        private readonly NotifyService $notifyService,
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
     * Beri tahu petugas (kader ATAU tenaga_kesehatan -- assigneeUser(), BUKAN cuma kader seperti
     * sebelumnya, karena assignment nakes tidak punya kader_id) kalau laporan kunjungannya
     * dinyatakan TIDAK VALID oleh super_admin (docs/planning/02 §11), berisi validation_note
     * supaya tahu alasannya dan bisa kunjungan ulang dengan benar. Dipanggil dari
     * VisitReportReviewService::validateReport() SETELAH assignment terkait sudah dikembalikan
     * ke status pending.
     *
     * 'push'+'fcm' (BUKAN cuma 'database' via VisitReportInvalidatedNotification seperti
     * sebelumnya) -- petugas WAJIB benar-benar dapat push, bukan cuma nongol di bell icon kalau
     * kebetulan dibuka. Data disimpan persis sama shape dgn VisitReportInvalidatedNotification
     * lama supaya NotificationResource/formatNotification() frontend tidak perlu berubah.
     */
    public function notifyReportInvalidated(VisitReport $report): void
    {
        $notifiable = $report->assignment?->assigneeUser();

        if ($notifiable === null) {
            return;
        }

        $payload = new NotificationPayload(
            type: 'visit_report_invalidated',
            title: 'Laporan Kunjungan Tidak Valid',
            body: "Laporan untuk pasien {$report->assignment->patient->nama} dinyatakan tidak valid."
                . ($report->validation_note ? " Catatan: {$report->validation_note}" : ''),
            data: [
                'type' => 'visit_report_invalidated',
                'visit_report_id' => $report->id,
                'assignment_id' => $report->assignment_id,
                'patient_nama' => $report->assignment->patient->nama,
                'validation_note' => $report->validation_note,
            ],
        );

        foreach (['push', 'fcm'] as $channelKey) {
            $this->channels[$channelKey]->send($notifiable, $payload);
        }
    }

    /**
     * Ringkasan H-1 untuk admin_puskesmas/pj_prolanis (permintaan user) -- SEBELUMNYA reminder
     * kunjungan cuma menyasar kader/tenaga_kesehatan yang ditugaskan (assigneeUser() di
     * deliver()), admin/PJ tidak pernah dapat notifikasi proaktif soal kunjungan besok, harus
     * buka kartu "Jadwal Kunjungan Mendatang" manual. SATU notifikasi per puskesmas (bukan per
     * kunjungan spt reminder kader) supaya tidak spam kalau ada banyak kunjungan sekaligus.
     * Dipanggil dari produli:notify-puskesmas-visit-summary, sekali sehari (BEDA dari
     * scheduleUpcomingReminders() yang twiceDaily) -- semantiknya "besok akan ada X kunjungan",
     * cukup sekali per hari, bukan diulang pagi & sore.
     *
     * Pola & try/catch per grup PERSIS sama seperti VisitReportService::notifyReportSubmitted()
     * -- 1 puskesmas gagal kirim tidak boleh menggagalkan grup lain.
     */
    public function notifyPuskesmasUpcomingVisitsSummary(): int
    {
        $tomorrow = Carbon::tomorrow();

        $counts = VisitAssignment::where('status', 'pending')
            ->whereDate('scheduled_date', $tomorrow)
            ->whereNotNull('puskesmas_id_snapshot')
            ->selectRaw('puskesmas_id_snapshot, count(*) as total')
            ->groupBy('puskesmas_id_snapshot')
            ->pluck('total', 'puskesmas_id_snapshot');

        $notified = 0;

        foreach ($counts as $puskesmasId => $count) {
            try {
                $this->notifyService->notify(
                    NotifiableTarget::rolesInPuskesmas(['admin_puskesmas', 'pj_prolanis'], (int) $puskesmasId),
                    new NotificationPayload(
                        type: 'visit_summary_tomorrow',
                        title: 'Ringkasan Kunjungan Besok',
                        body: "{$count} kunjungan dijadwalkan besok ({$tomorrow->toDateString()}) di wilayah Anda.",
                        data: [
                            'type' => 'visit_summary_tomorrow',
                            'puskesmas_id' => (int) $puskesmasId,
                            'count' => (int) $count,
                            'date' => $tomorrow->toDateString(),
                            'action_url' => '/dashboard/kunjungan',
                            'action_label' => 'Lihat Kunjungan',
                        ],
                    ),
                    ['push', 'fcm', 'ws'],
                );

                $notified++;
            } catch (Throwable $e) {
                Log::warning('NotificationService: gagal kirim ringkasan kunjungan besok', [
                    'puskesmas_id' => $puskesmasId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $notified;
    }
}
