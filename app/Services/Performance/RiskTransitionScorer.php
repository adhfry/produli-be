<?php

namespace App\Services\Performance;

use App\Models\PatientsCache;
use App\Models\RiskClassification;
use App\Models\RiskTransitionScore;
use App\Models\VisitReport;
use App\Support\RiskLevelScale;
use Carbon\CarbonInterface;

/**
 * Hitung & simpan skor 1 transisi risk_classifications (baris lama -> baris baru) untuk 1
 * pasien -- dipanggil dari RiskClassificationService::classify() TEPAT setelah baris
 * RiskClassification baru ditulis (lihat pemanggilan di sana). Dasar leaderboard "Top 5
 * Puskesmas Kinerja Terbaik" (App\Services\Performance\PuskesmasPerformanceScoringService).
 *
 * Aturan inti (spesifikasi scoring kinerja puskesmas):
 * - Matriks poin: 1 level perbaikan = +10 (mis. Berat->Sedang), 1 level perburukan = -10,
 *   KECUALI Terkendali->Terkendali yang diberi +2 (penghargaan retensi kondisi terkendali,
 *   BUKAN 0 -- supaya puskesmas yang mempertahankan pasien tetap terkendali tidak keliatan
 *   "diam" dibanding puskesmas yang pasiennya naik-turun).
 * - HANYA transisi dengan minimal 1 laporan kunjungan TERVALIDASI (validation_status='valid',
 *   super_admin sudah approve) di ANTARA kedua assessment yang dianggap "eligible" utk masuk
 *   agregasi kinerja puskesmas -- transisi tanpa bukti intervensi tetap DISIMPAN (audit trail
 *   lengkap, poin tetap dihitung apa adanya) tapi ditandai eligible=false, TIDAK ikut dijumlah
 *   PuskesmasPerformanceScoringService (supaya pasien yang kebetulan membaik tanpa program
 *   PRODULI tidak keliru dianggap keberhasilan puskesmas).
 * - Assessment PERTAMA pasien (tidak ada pembanding) TIDAK menghasilkan baris sama sekali --
 *   bukan "membaik", cuma belum pernah ada data sebelumnya.
 * - Idempotent lewat UNIQUE current_risk_classification_id (migration) -- replay sync SiLAKES
 *   atau produli:reclassify-risk yang menghasilkan RiskClassification baru identik (sudah
 *   di-guard classify() sendiri, tidak pernah sampai sini) tidak pernah dobel-hitung; kalau
 *   toh score() dipanggil 2x untuk current yang sama (mis. backfill dijalankan ulang), baris
 *   existing dikembalikan apa adanya, TIDAK membuat baris kedua.
 */
class RiskTransitionScorer
{
    private const RETENTION_POINT = 2;

    /**
     * @return int poin transisi (bisa negatif)
     */
    public function point(string $previousLevel, string $currentLevel): int
    {
        if ($previousLevel === 'tidak_berisiko' && $currentLevel === 'tidak_berisiko') {
            return self::RETENTION_POINT;
        }

        return (RiskLevelScale::numeric($previousLevel) - RiskLevelScale::numeric($currentLevel)) * 10;
    }

    /**
     * @return RiskTransitionScore|null null kalau $previous null (assessment pertama pasien).
     */
    public function score(PatientsCache $patient, ?RiskClassification $previous, RiskClassification $current): ?RiskTransitionScore
    {
        if ($previous === null) {
            return null;
        }

        $existing = RiskTransitionScore::where('current_risk_classification_id', $current->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $point = $this->point($previous->level, $current->level);
        $riskDelta = RiskLevelScale::numeric($previous->level) - RiskLevelScale::numeric($current->level);
        $validatedVisit = $this->findValidatedVisitBetween($patient, $previous, $current);

        return RiskTransitionScore::create([
            'patient_id' => $patient->id,
            'puskesmas_id' => $patient->puskesmas_id,
            'previous_risk_classification_id' => $previous->id,
            'current_risk_classification_id' => $current->id,
            'previous_risk_level' => $previous->level,
            'current_risk_level' => $current->level,
            'risk_delta' => $riskDelta,
            'base_point' => $point,
            'final_point' => $point,
            'related_validated_visit_id' => $validatedVisit?->id,
            'eligible' => $validatedVisit !== null,
            'calculated_at' => now(),
        ]);
    }

    /**
     * Laporan kunjungan TERVALIDASI TERBARU milik pasien ini yang terjadi di antara kedua
     * assessment -- dipakai sebagai bukti audit "intervensi apa yang menghasilkan perbaikan
     * ini". assessment_date (tanggal lab asli, kalau ada) dipakai sebagai batas jendela waktu
     * daripada computed_at -- konsisten dengan makna "Riwayat & Tren Kondisi" yang lain (lihat
     * RiskClassificationService::classify()), supaya jendela pencarian merefleksikan kapan
     * pasien benar-benar diperiksa, bukan kapan sistem kebetulan menghitung ulang.
     */
    private function findValidatedVisitBetween(PatientsCache $patient, RiskClassification $previous, RiskClassification $current): ?VisitReport
    {
        $windowStart = $this->effectiveDate($previous);
        $windowEnd = $this->effectiveDate($current);

        if ($windowStart->gt($windowEnd)) {
            // Data lab bisa masuk tidak berurutan (delta sync SiLAKES) -- lihat catatan serupa di
            // RiskClassificationService::classify(). Jendela dibalik supaya whereBetween tetap valid.
            [$windowStart, $windowEnd] = [$windowEnd, $windowStart];
        }

        return VisitReport::query()
            ->whereHas('assignment', fn ($q) => $q->where('patient_id', $patient->id))
            ->where('validation_status', 'valid')
            ->whereBetween('created_at', [$windowStart, $windowEnd])
            ->orderByDesc('created_at')
            ->first();
    }

    private function effectiveDate(RiskClassification $classification): CarbonInterface
    {
        return $classification->assessment_date ?? $classification->computed_at;
    }
}
