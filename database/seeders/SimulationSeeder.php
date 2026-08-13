<?php

namespace Database\Seeders;

use App\Models\Kecamatan;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * KHUSUS branch `dev`/lingkungan simulasi -- orkestrator tunggal yang dipanggil command
 * `produli:seed-simulation`. Urutan wajib: Roles -> (fallback wilayah/puskesmas kalau
 * kosong) -> akun 86 user demo -> pasien+assignment simulasi GPS.
 *
 * Fallback MasterWilayahSeeder/PuskesmasSeeder NORMALNYA tidak pernah kepakai -- dev DB
 * seharusnya sudah diisi lewat restore mysqldump produksi (lihat
 * docs/planning/14-setup-dev-simulasi-vps.md) yang sudah membawa kabupaten/kecamatan/
 * desa/puskesmas asli. Fallback ini cuma jaring pengaman kalau suatu saat dev DB dibangun
 * dari nol tanpa dump (mis. SiLAKES sedang tidak reachable, dump belum siap).
 */
class SimulationSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('SimulationSeeder tidak boleh dijalankan di environment production.');
        }

        $this->call(RolesSeeder::class);

        if (Kecamatan::count() === 0) {
            $this->command?->warn('Tabel kecamatan kosong -- menjalankan MasterWilayahSeeder + PuskesmasSeeder sebagai fallback (normalnya data ini sudah ada dari restore dump produksi).');
            $this->call(MasterWilayahSeeder::class);
            $this->call(PuskesmasSeeder::class);
        }

        $this->call(SimulationUsersSeeder::class);
        $this->call(SimulationPatientsSeeder::class);

        $this->command?->info('=== SimulationSeeder selesai -- lingkungan demo/simulasi siap. ===');
    }
}
