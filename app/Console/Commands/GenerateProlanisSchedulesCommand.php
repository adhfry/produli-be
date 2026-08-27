<?php

namespace App\Console\Commands;

use App\Services\Prolanis\ProlanisScheduleService;
use Illuminate\Console\Command;

/**
 * Scheduler dipanggil dailyAt (routes/console.php) -- jaga jadwal kegiatan Prolanis tiap pasien
 * tetap mutakhir begitu ada data lab baru masuk (SyncSilakesService). Lihat docblock
 * ProlanisScheduleService::generateSchedules().
 */
class GenerateProlanisSchedulesCommand extends Command
{
    protected $signature = 'produli:generate-prolanis-schedules';

    protected $description = 'Generate/perbarui jadwal kegiatan Prolanis dari tanggal lab terbaru tiap pasien';

    public function handle(ProlanisScheduleService $service): int
    {
        $count = $service->generateSchedules();

        $this->info("Jadwal Prolanis dibuat/diperbarui: {$count}.");

        return self::SUCCESS;
    }
}
