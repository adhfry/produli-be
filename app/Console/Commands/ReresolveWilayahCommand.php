<?php

namespace App\Console\Commands;

use App\Models\PatientsCache;
use App\Services\Wilayah\WilayahResolver;
use Illuminate\Console\Command;

/**
 * Hitung ulang desa_id/wilayah_status/puskesmas_id/puskesmas_resolution_method SEMUA pasien
 * dari kel_desa_raw/kecamatan_raw yang sudah tersimpan lokal -- tanpa re-sync ke SiLAKES.
 * Pola sama seperti kopipu:reclassify-risk. Perlu dijalankan tiap kali data master wilayah
 * berubah (desa/puskesmas/kecamatan diseed ulang, atau kopipu:import-desa-puskesmas dijalankan)
 * -- patients_cache TIDAK otomatis reresolve sendiri saat master wilayah berubah.
 */
class ReresolveWilayahCommand extends Command
{
    protected $signature = 'kopipu:reresolve-wilayah';

    protected $description = 'Hitung ulang resolusi desa/kecamatan/puskesmas semua pasien dari cache lokal (tanpa re-sync SiLAKES)';

    public function handle(WilayahResolver $resolver): int
    {
        $total = 0;
        $perWilayahStatus = ['resolved' => 0, 'unresolved' => 0, 'unknown' => 0, 'out_of_scope' => 0];
        $perPuskesmasMethod = ['desa' => 0, 'kecamatan_fallback' => 0, 'manual' => 0, 'kader_verified' => 0, 'unresolvable' => 0];

        PatientsCache::chunkById(500, function ($patients) use ($resolver, &$total, &$perWilayahStatus, &$perPuskesmasMethod) {
            foreach ($patients as $patient) {
                $total++;

                $resolution = $resolver->resolve($patient->kel_desa_raw, $patient->kecamatan_raw);
                $puskesmas = $resolver->resolvePuskesmas($resolution->desaId, $resolution->kecamatanId);

                $patient->forceFill([
                    'desa_id' => $resolution->desaId,
                    'kecamatan_id' => $resolution->kecamatanId,
                    'wilayah_status' => $resolution->wilayahStatus,
                    'puskesmas_id' => $puskesmas['puskesmas_id'],
                    'puskesmas_resolution_method' => $puskesmas['method'],
                ])->save();

                $perWilayahStatus[$resolution->wilayahStatus]++;
                $perPuskesmasMethod[$puskesmas['method']]++;
            }
        });

        $this->info(sprintf('Selesai: %d pasien diproses.', $total));
        $this->table(['wilayah_status', 'jumlah'], collect($perWilayahStatus)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->table(['puskesmas_resolution_method', 'jumlah'], collect($perPuskesmasMethod)->map(fn ($v, $k) => [$k, $v])->values()->all());

        return self::SUCCESS;
    }
}
