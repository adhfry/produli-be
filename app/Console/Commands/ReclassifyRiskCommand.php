<?php

namespace App\Console\Commands;

use App\Models\PatientsCache;
use App\Services\Risk\RiskClassificationService;
use Illuminate\Console\Command;

/**
 * Hitung ulang klasifikasi risiko SEMUA pasien dari lab_results_cache yang sudah tersimpan
 * lokal -- tanpa re-sync ke SiLAKES. Berguna tiap kali risk_thresholds ditambah/diubah, atau
 * (kejadian nyata di proyek ini) nama parameter di RiskClassificationService diperbaiki
 * setelah sempat mismatch dengan data yang sudah terlanjur tersinkron -- data lama TIDAK
 * otomatis reklasifikasi sendiri, cuma pasien dengan hasil lab BARU yang ke-trigger lewat
 * SyncSilakesService.
 */
class ReclassifyRiskCommand extends Command
{
    protected $signature = 'produli:reclassify-risk';

    protected $description = 'Hitung ulang klasifikasi risiko semua pasien dari cache lokal (tanpa re-sync SiLAKES)';

    public function handle(RiskClassificationService $service): int
    {
        $total = 0;
        $classified = 0;
        $perLevel = ['tidak_berisiko' => 0, 'ringan' => 0, 'sedang' => 0, 'berat' => 0];

        PatientsCache::chunkById(500, function ($patients) use ($service, &$total, &$classified, &$perLevel) {
            foreach ($patients as $patient) {
                $total++;
                $result = $service->classify($patient);

                if ($result) {
                    $classified++;
                    $perLevel[$result->level]++;
                }
            }
        });

        $this->info(sprintf(
            'Selesai: %d pasien diproses, %d terklasifikasi (tidak_berisiko=%d, ringan=%d, sedang=%d, berat=%d).',
            $total,
            $classified,
            $perLevel['tidak_berisiko'],
            $perLevel['ringan'],
            $perLevel['sedang'],
            $perLevel['berat'],
        ));

        return self::SUCCESS;
    }
}
