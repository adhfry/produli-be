<?php

namespace App\Console\Commands;

use App\Exceptions\SinkronSedangBerjalanException;
use App\Models\IntegrationSyncLog;
use App\Services\Silakes\SyncSilakesService;
use Illuminate\Console\Command;

/**
 * Tarik SEMUA pasien Prolanis dari SiLAKES sekali jalan (blueprint docs/planning/16 §9 no. 1,
 * 2026-11-17). Sinkron harian hanya menarik pasien yang berubah, jadi pasien Prolanis yang dulu
 * dilewati gerbang "harus punya hasil lab" tidak akan pernah masuk tanpa perintah ini.
 *
 * Aman diulang (upsert), memegang kunci sinkron bersama, tidak mengubah batas "data baru" sinkron
 * harian, puskesmas yang sudah terkunci tidak berubah. --uji hanya menghitung.
 */
class TarikSemuaPasienCommand extends Command
{
    protected $signature = 'produli:tarik-semua-pasien {--uji : Hanya hitung pasien baru/diperbarui, tanpa menulis}';

    protected $description = 'Tarik semua pasien Prolanis dari SiLAKES (termasuk yang belum punya hasil lab)';

    public function handle(SyncSilakesService $service): int
    {
        $uji = (bool) $this->option('uji');
        $mulai = now();

        try {
            $hasil = $service->tarikSemuaPasien($uji);
        } catch (SinkronSedangBerjalanException $e) {
            $this->warn($e->getMessage().' Tidak ada yang diubah.');

            return self::FAILURE;
        }

        if (! $uji) {
            IntegrationSyncLog::create([
                'service_name' => 'TarikSemuaPasienCommand',
                'endpoint' => 'patients',
                'requested_at' => $mulai,
                'status' => 'success',
                'records_count' => $hasil['baru'] + $hasil['diperbarui'],
                'details' => $hasil,
            ]);
        }

        $this->info(($uji ? '[UJI, tidak ada yang ditulis] ' : '').json_encode($hasil));

        return self::SUCCESS;
    }
}
