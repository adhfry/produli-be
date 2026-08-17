<?php

namespace App\Console\Commands;

use App\Models\IntegrationSyncLog;
use App\Services\Silakes\SyncSilakesService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Scheduler dipanggil harian (routes/console.php), tapi command ini yang menegakkan
 * interval minimal 24 jam sejak run SUKSES terakhir — dicek lewat integration_sync_logs,
 * bukan cron dengan interval "tiap N hari" di field hari (patah di batas bulan). 24 jam
 * (bukan 48) -- keputusan eksplisit user supaya auto-sync selalu jalan otomatis 24 jam
 * sejak sync terakhir.
 */
class SyncSilakesCommand extends Command
{
    private const MIN_HOURS_BETWEEN_RUNS = 24;

    protected $signature = 'produli:sync-silakes
        {--force : Jalankan meski run sukses terakhir belum 24 jam lalu}';

    protected $description = 'Sync pasien & hasil lab dari SiLAKES (throttle: minimal 24 jam sejak run sukses terakhir)';

    public function handle(SyncSilakesService $service): int
    {
        $lastSuccessAt = IntegrationSyncLog::where('service_name', 'SyncSilakesService')
            ->where('status', 'success')
            ->max('requested_at');

        if (! $this->option('force') && $lastSuccessAt && Carbon::parse($lastSuccessAt)->addHours(self::MIN_HOURS_BETWEEN_RUNS)->isFuture()) {
            $this->info(sprintf(
                'Skip: run sukses terakhir %s (belum %d jam).',
                Carbon::parse($lastSuccessAt)->diffForHumans(),
                self::MIN_HOURS_BETWEEN_RUNS,
            ));

            return self::SUCCESS;
        }

        try {
            $result = $service->runAndLog();

            $this->info('Sync berhasil: '.json_encode($result));

            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error('Sync gagal: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
