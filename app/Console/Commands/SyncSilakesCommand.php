<?php

namespace App\Console\Commands;

use App\Models\IntegrationSyncLog;
use App\Services\Silakes\SyncSilakesService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Scheduler dipanggil harian (routes/console.php), tapi command ini yang menegakkan
 * interval minimal 48 jam sejak run SUKSES terakhir — dicek lewat integration_sync_logs,
 * bukan cron dengan interval "tiap 2 hari" di field hari (patah di batas bulan).
 */
class SyncSilakesCommand extends Command
{
    private const MIN_HOURS_BETWEEN_RUNS = 48;

    protected $signature = 'kopipu:sync-silakes
        {--force : Jalankan meski run sukses terakhir belum 48 jam lalu}';

    protected $description = 'Sync pasien & hasil lab dari SiLAKES (throttle: minimal 48 jam sejak run sukses terakhir)';

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

        $requestedAt = now();

        try {
            $result = $service->run();

            IntegrationSyncLog::create([
                'service_name' => 'SyncSilakesService',
                'endpoint' => 'patients+lab-results',
                'requested_at' => $requestedAt,
                'status' => 'success',
                'records_count' => $result['patients_synced'] + $result['lab_results_synced'],
                'details' => $result,
            ]);

            $this->info('Sync berhasil: '.json_encode($result));

            return self::SUCCESS;
        } catch (Throwable $e) {
            IntegrationSyncLog::create([
                'service_name' => 'SyncSilakesService',
                'endpoint' => 'patients+lab-results',
                'requested_at' => $requestedAt,
                'status' => 'failed',
                'records_count' => 0,
                'details' => ['error' => $e->getMessage()],
            ]);

            report($e);
            $this->error('Sync gagal: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
