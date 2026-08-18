<?php

namespace App\Console\Commands;

use App\Models\PatientsCache;
use App\Models\RiskClassification;
use App\Services\Performance\RiskTransitionScorer;
use Illuminate\Console\Command;

/**
 * Backfill risk_transition_scores dari SELURUH histori risk_classifications yang sudah ada --
 * dijalankan SEKALI manual setelah deploy fitur scoring kinerja puskesmas (Top 5), supaya data
 * lama (sebelum RiskTransitionScorer ada) ikut tercakup di leaderboard, bukan cuma transisi
 * baru sejak fitur ini dipasang.
 *
 * Aman dijalankan berkali-kali (idempotent) -- RiskTransitionScorer::score() sendiri sudah
 * menjaga lewat UNIQUE current_risk_classification_id, baris yang sudah ada dikembalikan apa
 * adanya, tidak pernah dobel. TIDAK mengubah/menghapus risk_classifications atau visit_reports
 * sama sekali -- murni baca, tulis HANYA ke risk_transition_scores (append-only).
 */
class BackfillRiskTransitionScoresCommand extends Command
{
    protected $signature = 'produli:backfill-risk-transition-scores';

    protected $description = 'Hitung retroaktif risk_transition_scores dari seluruh histori risk_classifications (aman dijalankan berkali-kali)';

    public function handle(RiskTransitionScorer $scorer): int
    {
        $totalTransitions = 0;
        $createdOrExisting = 0;
        $eligible = 0;

        PatientsCache::chunkById(200, function ($patients) use ($scorer, &$totalTransitions, &$createdOrExisting, &$eligible) {
            foreach ($patients as $patient) {
                $history = RiskClassification::where('patient_id', $patient->id)
                    ->orderByRaw('COALESCE(assessment_date, computed_at) asc')
                    ->orderBy('id')
                    ->get();

                $previous = null;

                foreach ($history as $current) {
                    if ($previous !== null) {
                        $totalTransitions++;
                        $score = $scorer->score($patient, $previous, $current);

                        if ($score !== null) {
                            $createdOrExisting++;
                            if ($score->eligible) {
                                $eligible++;
                            }
                        }
                    }

                    $previous = $current;
                }
            }
        });

        $this->info(sprintf(
            'Selesai: %d transisi diproses, %d baris risk_transition_scores ada/dibuat (%d di antaranya eligible utk leaderboard).',
            $totalTransitions,
            $createdOrExisting,
            $eligible,
        ));

        return self::SUCCESS;
    }
}
