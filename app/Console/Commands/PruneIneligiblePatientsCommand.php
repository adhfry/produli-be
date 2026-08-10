<?php

namespace App\Console\Commands;

use App\Models\LabResultCache;
use App\Models\PatientsCache;
use Illuminate\Console\Command;

/**
 * Hapus patients_cache yang tidak (lagi) eligible -- tidak punya satupun baris
 * lab_results_cache (yang sejak SyncSilakesService::syncLabResults() sudah difilter ke
 * tanggal_periksa >= kopipu.silakes.lab_results_since). Pasien begini SENGAJA dikeluarkan
 * total dari KOPIPU (keputusan produk), bukan cuma disembunyikan dari klasifikasi risiko.
 *
 * Pembersihan satu-arah dan eksplisit (bukan otomatis tiap sync) -- SyncSilakesService cuma
 * MENCEGAH pasien baru yang tidak eligible ikut ter-cache (lihat isEligible()), tidak
 * menghapus baris lama yang mungkin masih dipakai relasi lain. Command ini yang menghapus,
 * dan HANYA yang aman (tidak punya visit_assignments/risk_classifications terkait) --
 * baris yang masih punya relasi dilaporkan, bukan dipaksa hapus (FK constraint akan
 * menolak delete-nya juga kalau dipaksa).
 */
class PruneIneligiblePatientsCommand extends Command
{
    protected $signature = 'kopipu:prune-ineligible-patients
        {--dry-run : Tampilkan preview tanpa menghapus}';

    protected $description = 'Hapus pasien tercache yang tidak punya riwayat lab qualifying (dan tidak punya relasi aktif)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $ineligible = PatientsCache::query()
            ->whereNotIn('external_patient_id', LabResultCache::select('patient_id')->distinct())
            ->withCount(['visitAssignments', 'riskClassifications'])
            ->get();

        $deletable = $ineligible->filter(fn (PatientsCache $p) => $p->visit_assignments_count === 0 && $p->risk_classifications_count === 0);
        $blocked = $ineligible->diff($deletable);

        if ($blocked->isNotEmpty()) {
            $this->warn(sprintf('%d pasien tidak eligible TAPI punya relasi aktif -- TIDAK dihapus:', $blocked->count()));
            foreach ($blocked as $patient) {
                $this->line("  - #{$patient->id} {$patient->nama} (assignments={$patient->visit_assignments_count}, classifications={$patient->risk_classifications_count})");
            }
        }

        if (! $dryRun) {
            PatientsCache::whereIn('id', $deletable->pluck('id'))->delete();
        }

        $this->info(sprintf(
            '%s%d pasien tidak eligible dihapus, %d dilewati karena masih punya relasi.',
            $dryRun ? '[DRY RUN] ' : '',
            $deletable->count(),
            $blocked->count(),
        ));

        return self::SUCCESS;
    }
}
