<?php

namespace App\Console\Commands;

use App\Models\PatientsCache;
use App\Models\RiskClassification;
use App\Services\Risk\RiskClassificationService;
use Illuminate\Console\Command;

/**
 * Hitung ulang klasifikasi risiko SEMUA pasien dari lab_results_cache yang sudah tersimpan
 * lokal -- tanpa re-sync ke SiLAKES. Berguna tiap kali risk_thresholds ditambah/diubah, atau
 * (kejadian nyata di proyek ini) nama parameter di RiskClassificationService diperbaiki
 * setelah sempat mismatch dengan data yang sudah terlanjur tersinkron -- data lama TIDAK
 * otomatis reklasifikasi sendiri, cuma pasien dengan hasil lab BARU yang ke-trigger lewat
 * SyncSilakesService.
 *
 * KEJADIAN NYATA (bikin dua kali salah baca hasil, di produksi maupun dev): $perLevel di
 * bawah cuma menghitung baris yang BARU DITULIS -- RiskClassificationService::classify()
 * sengaja return null (tidak menulis apa-apa) kalau level+kriteria barunya PERSIS SAMA dengan
 * is_latest yang sudah tersimpan (guard idempotensi, cegah duplikat riwayat). Pasien yang
 * SUDAH benar sedang/berat dari run sebelumnya jadi tidak pernah ikut ke-hitung di sini,
 * padahal levelnya tetap sedang/berat -- "sedang=0, berat=0" TIDAK BERARTI tidak ada pasien
 * sedang/berat, cuma berarti tidak ada YANG BERUBAH ke situ. Makanya method ini sekarang juga
 * mencetak distribusi TOTAL is_latest=true saat ini (query terpisah, dieksekusi SETELAH
 * seluruh reclassify selesai), supaya delta dan total tidak lagi tertukar dibaca.
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
            'Selesai: %d pasien diproses, %d baris BARU ditulis/berubah (tidak_berisiko=%d, ringan=%d, sedang=%d, berat=%d).',
            $total,
            $classified,
            $perLevel['tidak_berisiko'],
            $perLevel['ringan'],
            $perLevel['sedang'],
            $perLevel['berat'],
        ));

        $totalPerLevel = RiskClassification::where('is_latest', true)
            ->selectRaw('level, count(*) as total')
            ->groupBy('level')
            ->pluck('total', 'level');

        $this->info(sprintf(
            'Distribusi TOTAL saat ini (bukan cuma yang baru berubah): tidak_berisiko=%d, ringan=%d, sedang=%d, berat=%d.',
            $totalPerLevel['tidak_berisiko'] ?? 0,
            $totalPerLevel['ringan'] ?? 0,
            $totalPerLevel['sedang'] ?? 0,
            $totalPerLevel['berat'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
