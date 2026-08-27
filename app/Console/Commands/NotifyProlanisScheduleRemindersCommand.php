<?php

namespace App\Console\Commands;

use App\Services\Prolanis\ProlanisScheduleService;
use Illuminate\Console\Command;

/**
 * Scheduler dipanggil dailyAt (routes/console.php) -- kirim pengingat H-1 minggu (permintaan
 * user, config('produli.prolanis_schedule.reminder_days_before')) ke tiap puskesmas yang
 * punya pasien terjadwal kegiatan Prolanis pada tanggal itu. Lihat docblock
 * ProlanisScheduleService::sendDueReminders().
 */
class NotifyProlanisScheduleRemindersCommand extends Command
{
    protected $signature = 'produli:notify-prolanis-schedule-reminders';

    protected $description = 'Kirim pengingat H-1 minggu jadwal kegiatan Prolanis ke tiap puskesmas';

    public function handle(ProlanisScheduleService $service): int
    {
        $count = $service->sendDueReminders();

        $this->info("Pengingat jadwal Prolanis dikirim ke {$count} puskesmas.");

        return self::SUCCESS;
    }
}
