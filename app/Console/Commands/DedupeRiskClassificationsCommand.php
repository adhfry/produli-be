<?php

namespace App\Console\Commands;

use App\Models\PatientsCache;
use App\Models\RiskClassification;
use Illuminate\Console\Command;

/**
 * One-off pembersihan baris risk_classifications duplikat -- RiskClassificationService::
 * classify() dulu SELALU menulis baris baru tiap dipanggil, TANPA cek apakah level/
 * criteria_snapshot-nya sungguhan berbeda dari is_latest sekarang (lihat commit yang menambah
 * guard idempotensi di classify()). Delta sync yang melihat "ada data baru" padahal nilainya
 * sama persis, DITAMBAH pemanggilan manual produli:reclassify-risk berkali-kali, menghasilkan
 * riwayat penuh baris identik (parameter+nilai+level sama, cuma computed_at beda) -- bikin
 * "Riwayat & Tren Kondisi" di detail pasien menyesatkan (kelihatan ada banyak pemeriksaan
 * padahal cuma 1 kejadian nyata).
 *
 * Jalan SEKALI setelah guard idempotensi ditambahkan, supaya data historis yang SUDAH
 * terlanjur berduplikat ikut rapi -- guard di classify() sendiri cuma mencegah duplikat BARU,
 * tidak membersihkan yang lama.
 */
class DedupeRiskClassificationsCommand extends Command
{
    protected $signature = 'produli:dedupe-risk-classifications {--dry-run : Tampilkan apa yang AKAN dihapus tanpa benar-benar menghapus}';

    protected $description = 'Hapus baris risk_classifications duplikat berturut-turut (level+criteria_snapshot identik) per pasien';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $totalDeleted = 0;
        $patientsAffected = 0;

        PatientsCache::whereHas('riskClassifications')->chunkById(200, function ($patients) use (&$totalDeleted, &$patientsAffected, $dryRun) {
            foreach ($patients as $patient) {
                $rows = RiskClassification::where('patient_id', $patient->id)
                    ->orderBy('computed_at')
                    ->orderBy('id')
                    ->get();

                if ($rows->count() < 2) {
                    continue;
                }

                $toDelete = [];
                $previous = null;

                // Baris PERTAMA dari tiap "run" berturut-turut yang identik dipertahankan --
                // computed_at-nya paling mewakili "kapan kondisi ini pertama kali tercatat".
                foreach ($rows as $row) {
                    if ($previous !== null
                        && $previous->level === $row->level
                        && $this->criteriaEquivalent($previous->criteria_snapshot, $row->criteria_snapshot)) {
                        $toDelete[] = $row->id;

                        continue;
                    }

                    $previous = $row;
                }

                if ($toDelete === []) {
                    continue;
                }

                $patientsAffected++;
                $totalDeleted += count($toDelete);

                if (! $dryRun) {
                    RiskClassification::whereIn('id', $toDelete)->delete();

                    // is_latest bisa ikut kehapus kalau baris TERAKHIR (paling baru) adalah
                    // duplikat yang dibuang -- pastikan baris yang benar-benar tersisa paling
                    // baru per pasien tetap is_latest=true.
                    RiskClassification::where('patient_id', $patient->id)->update(['is_latest' => false]);
                    RiskClassification::where('patient_id', $patient->id)
                        ->orderByDesc('computed_at')
                        ->orderByDesc('id')
                        ->limit(1)
                        ->update(['is_latest' => true]);
                }
            }
        });

        $verb = $dryRun ? 'AKAN dihapus (dry-run, belum benar-benar dihapus)' : 'dihapus';
        $this->info("Selesai: {$totalDeleted} baris duplikat {$verb} dari {$patientsAffected} pasien.");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $a
     * @param  array<int, array<string, mixed>>  $b
     */
    private function criteriaEquivalent(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }

        foreach ($a as $i => $rowA) {
            $rowB = $b[$i] ?? null;

            if ($rowB === null
                || $rowA['parameter'] !== $rowB['parameter']
                || (float) $rowA['value'] !== (float) $rowB['value']
                || $rowA['tanggal_periksa'] !== $rowB['tanggal_periksa']
                || $rowA['level'] !== $rowB['level']) {
                return false;
            }
        }

        return true;
    }
}
