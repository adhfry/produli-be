<?php

namespace App\Console\Commands;

use App\Services\Notification\NotificationService;
use Illuminate\Console\Command;

/**
 * Scheduler dipanggil dailyAt('16:00') (routes/console.php, jam sama dgn reminder H-1 kader) --
 * kirim ringkasan "besok akan ada X kunjungan" ke admin_puskesmas/pj_prolanis, lihat docblock
 * NotificationService::notifyPuskesmasUpcomingVisitsSummary() untuk alasan ini terpisah dari
 * produli:send-visit-reminders (target beda: admin/PJ per-puskesmas, bukan kader/nakes
 * per-kunjungan) dan cuma sekali sehari (bukan twiceDaily).
 */
class NotifyPuskesmasVisitSummaryCommand extends Command
{
    protected $signature = 'produli:notify-puskesmas-visit-summary';

    protected $description = 'Kirim ringkasan H-1 kunjungan besok ke admin_puskesmas/pj_prolanis per puskesmas';

    public function handle(NotificationService $service): int
    {
        $notified = $service->notifyPuskesmasUpcomingVisitsSummary();

        $this->info("Ringkasan kunjungan besok dikirim ke {$notified} puskesmas.");

        return self::SUCCESS;
    }
}
