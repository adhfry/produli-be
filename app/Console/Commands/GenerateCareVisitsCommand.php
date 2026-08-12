<?php

namespace App\Console\Commands;

use App\Services\Visit\CareAssignmentCadenceService;
use Illuminate\Console\Command;

/**
 * Scan harian semua rencana kunjungan berulang (care_assignments) aktif, generate kunjungan
 * yang sudah due (revisi Bu Kadis) -- lihat CareAssignmentCadenceService untuk logika due-nya.
 */
class GenerateCareVisitsCommand extends Command
{
    protected $signature = 'produli:generate-care-visits';

    protected $description = 'Generate kunjungan berikutnya untuk rencana kunjungan berulang (kader/tenaga kesehatan) yang sudah due';

    public function handle(CareAssignmentCadenceService $service): int
    {
        $count = $service->generateDueVisits();

        $this->info("Selesai: {$count} kunjungan baru digenerate dari rencana berulang yang due.");

        return self::SUCCESS;
    }
}
