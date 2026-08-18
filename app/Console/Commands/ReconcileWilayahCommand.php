<?php

namespace App\Console\Commands;

use App\Models\PatientsCache;
use App\Models\WilayahMapping;
use App\Services\Wilayah\WilayahResolver;
use Illuminate\Console\Command;

/**
 * Proses ulang desa_id/wilayah_status/puskesmas_id semua patients_cache dari
 * kel_desa_raw/kecamatan_raw yang SUDAH tersimpan lokal — tanpa panggil SiLAKES lagi.
 * Berguna tiap kali logika WilayahResolver diperbaiki/ditambah alias baru.
 */
class ReconcileWilayahCommand extends Command
{
    protected $signature = 'produli:reconcile-wilayah
        {--fresh : Kosongkan cache wilayah_mapping dulu sebelum reproses (hanya aman selama belum ada review manual tersimpan)}';

    protected $description = 'Reproses resolusi wilayah semua pasien di cache lokal (tanpa re-sync ke SiLAKES)';

    public function handle(WilayahResolver $resolver): int
    {
        if ($this->option('fresh')) {
            $this->warn('--fresh: mengosongkan wilayah_mapping sebelum reproses.');
            WilayahMapping::truncate();
        }

        $changed = 0;
        $total = 0;
        $statusBefore = PatientsCache::selectRaw('wilayah_status, count(*) as c')->groupBy('wilayah_status')->pluck('c', 'wilayah_status');

        PatientsCache::chunkById(500, function ($patients) use ($resolver, &$changed, &$total) {
            foreach ($patients as $patient) {
                $total++;

                if ($patient->puskesmas_resolution_method === 'manual') {
                    // Override manual TIDAK PERNAH ditimpa reconcile otomatis -- lihat catatan
                    // sama di SyncSilakesService::upsertPatient().
                    continue;
                }

                $resolution = $resolver->resolve($patient->kel_desa_raw, $patient->kecamatan_raw);
                $puskesmas = $resolver->resolvePuskesmas($resolution->desaId, $resolution->kecamatanId);

                $dirty = $patient->desa_id !== $resolution->desaId
                    || $patient->wilayah_status !== $resolution->wilayahStatus
                    || $patient->puskesmas_id !== $puskesmas['puskesmas_id']
                    || $patient->puskesmas_resolution_method !== $puskesmas['method'];

                if ($dirty) {
                    $patient->update([
                        'desa_id' => $resolution->desaId,
                        'wilayah_status' => $resolution->wilayahStatus,
                        'puskesmas_id' => $puskesmas['puskesmas_id'],
                        'puskesmas_resolution_method' => $puskesmas['method'],
                    ]);
                    $changed++;
                }
            }
        });

        $statusAfter = PatientsCache::selectRaw('wilayah_status, count(*) as c')->groupBy('wilayah_status')->pluck('c', 'wilayah_status');

        $this->info("Selesai: {$total} pasien diproses, {$changed} berubah.");
        $this->table(
            ['wilayah_status', 'sebelum', 'sesudah'],
            collect(['resolved', 'unresolved', 'unknown', 'out_of_scope'])->map(fn ($s) => [
                $s, $statusBefore[$s] ?? 0, $statusAfter[$s] ?? 0,
            ])->toArray(),
        );

        return self::SUCCESS;
    }
}
