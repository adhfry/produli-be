<?php

namespace App\Console\Commands;

use App\Models\IntegrationSyncLog;
use App\Services\Silakes\SyncSilakesService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Scheduler dipanggil harian (routes/console.php), tapi command ini yang menegakkan
 * interval minimal jam sejak run SUKSES terakhir — dicek lewat integration_sync_logs,
 * bukan cron dengan interval "tiap N hari" di field hari (patah di batas bulan).
 *
 * BUG NYATA (ditemukan lewat laporan user "sync auto tidak jalan", audit
 * integration_sync_logs) — SEBELUMNYA ambang ini persis 24 jam, SAMA PERSIS dengan
 * interval dailyAt('02:00'). `schedule:run` dipanggil cron sistem SETIAP MENIT, dan
 * eksekusi sungguhannya (bootstrap Laravel + proses sync) selalu mendarat beberapa
 * DETIK setelah 02:00:00 (mis. 02:00:03, 02:00:06 -- bervariasi tiap hari, jitter wajar).
 * Akibatnya `lastSuccessAt->addHours(24)` SELALU sedikit lebih besar dari 02:00:00 pas
 * di hari berikutnya (mis. run kemarin 02:00:06 -> ambang besok 02:00:06, tapi cek besok
 * jalan di 02:00:00-02:00:05 -> masih future -> SKIP), lalu baru jalan lagi lusa begitu
 * marginnya sudah hampir 48 jam. Pola nyata di log produksi: skip SETIAP HARI KEDUA,
 * bukan cuma sesekali -- dikonfirmasi TIDAK ADA satu pun baris 'failed', murni race
 * kondisi ambang vs jitter. Turunkan ke 23 jam (buffer 1 jam, jauh lebih besar dari
 * jitter beberapa detik) supaya cek besok SELALU sudah lewat ambang, tanpa membuka
 * celah 2x run sungguhan dalam 1 hari kalender (interval trigger tetap 24 jam).
 * Tombol manual sinkronisasi (SilakesSyncController) TIDAK lewat command ini sama
 * sekali -- throttle ini TIDAK berlaku untuknya.
 */
class SyncSilakesCommand extends Command
{
    private const MIN_HOURS_BETWEEN_RUNS = 23;

    protected $signature = 'produli:sync-silakes
        {--force : Jalankan meski run sukses terakhir belum 23 jam lalu}';

    protected $description = 'Sync pasien & hasil lab dari SiLAKES (throttle: minimal 23 jam sejak run sukses terakhir)';

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
