<?php

namespace App\Console\Commands;

use App\Services\Notification\NotificationService;
use Illuminate\Console\Command;

/**
 * Scheduler dipanggil twiceDaily (routes/console.php) PERSIS di jam h_minus_1_time dan
 * same_day_time (config/kopipu.php) -- supaya reminder H-1 (sore) dan hari-H (pagi) sama-sama
 * bisa terkirim dekat waktu targetnya, bukan menunggu sampai run berikutnya (docs/planning/02 §8).
 */
class SendVisitRemindersCommand extends Command
{
    protected $signature = 'kopipu:send-visit-reminders';

    protected $description = 'Jadwalkan & kirim reminder H-1/hari-H untuk kunjungan kader (docs/planning/02 §8)';

    public function handle(NotificationService $service): int
    {
        $created = $service->scheduleUpcomingReminders();
        $dispatched = $service->dispatchDueReminders();

        $this->info("Reminder dibuat: {$created}. Reminder di-dispatch ke queue: {$dispatched}.");

        return self::SUCCESS;
    }
}
