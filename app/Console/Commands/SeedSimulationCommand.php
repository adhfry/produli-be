<?php

namespace App\Console\Commands;

use Database\Seeders\SimulationPatientsSeeder;
use Database\Seeders\SimulationSeeder;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * KHUSUS branch `dev`/lingkungan simulasi -- satu perintah yang dipanggil skrip VPS
 * (scripts/dev-setup.sh, scripts/dev-reset-simulation.sh) untuk menyiapkan/mereset
 * lingkungan demo end-to-end sebelum presentasi ke Bu Kadis.
 *
 * Tanpa flag: idempoten, aman dipanggil kapan pun (mis. tiap kali skrip deploy jalan).
 * --reset-demo: TAMBAHAN mengembalikan pasien+assignment simulasi GPS ke state AWAL
 * (sebelum ada kunjungan) -- cepat, TIDAK menyentuh data dump/86 akun user. Ini yang
 * dipakai berulang kali saat gladi bersih, BUKAN migrate:fresh (itu akan menghapus
 * seluruh data dump produksi yang sudah di-restore).
 */
class SeedSimulationCommand extends Command
{
    protected $signature = 'produli:seed-simulation
        {--reset-demo : Kembalikan pasien & assignment simulasi GPS ke state awal sebelum seed ulang}';

    protected $description = 'Siapkan/reset lingkungan demo simulasi (akun 86 user + pasien uji GPS) -- khusus dev, menolak jalan di production';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('produli:seed-simulation tidak boleh dijalankan di environment production.');

            return self::FAILURE;
        }

        try {
            if ($this->option('reset-demo')) {
                $this->warn('--reset-demo: mengembalikan pasien & assignment simulasi GPS ke state awal.');
                SimulationPatientsSeeder::resetDemoState();
            }

            $this->call(SimulationSeeder::class);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
