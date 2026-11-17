<?php

namespace App\Console\Commands;

use App\Models\IntegrationSyncLog;
use App\Services\Patient\PatientSnapshotService;
use App\Services\Silakes\SyncSilakesService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Snapshot kondisi semua pasien hari ini (blueprint docs/planning/16 §4c), dijadwalkan 02:30 setelah
 * sinkron 02:00. Menunggu kunci sinkron (maks. 30 menit) supaya tidak memotret data setengah
 * tersinkron. Aman diulang di hari yang sama.
 */
class SnapshotHarianCommand extends Command
{
    protected $signature = 'produli:snapshot-harian';

    protected $description = 'Catat kondisi (level risiko & puskesmas) semua pasien hari ini';

    public function handle(PatientSnapshotService $snapshot): int
    {
        $mulai = now();
        try {
            $jumlah = Cache::lock(SyncSilakesService::KUNCI, SyncSilakesService::KUNCI_TTL_DETIK)
                ->block(1800, fn () => $snapshot->buatHariIni());
        } catch (LockTimeoutException) {
            $this->error('Sinkron SiLAKES belum selesai setelah 30 menit; snapshot hari ini belum dibuat.');

            return self::FAILURE;
        }

        IntegrationSyncLog::create([
            'service_name' => 'SnapshotHarianCommand',
            'endpoint' => 'patient_daily_snapshots',
            'requested_at' => $mulai,
            'status' => 'success',
            'records_count' => $jumlah,
            'details' => ['snapshot_date' => $mulai->toDateString()],
        ]);
        $this->info("Snapshot {$mulai->toDateString()}: {$jumlah} pasien.");

        return self::SUCCESS;
    }
}
