<?php

namespace App\Services\Performance;

use App\Models\PatientDailySnapshot;
use App\Models\PatientsCache;
use App\Models\RiskClassification;
use App\Models\RiskTransitionScore;
use App\Models\VisitReport;
use App\Support\RiskLevelScale;
use Carbon\CarbonInterface;

/**
 * Hitung & simpan skor 1 transisi risk_classifications (baris lama -> baris baru) untuk 1 pasien,
 * dipanggil RiskClassificationService::classify() tepat setelah baris baru ditulis. Dasar
 * peringkat kinerja puskesmas (PuskesmasPerformanceScoringService).
 *
 * Aturan v2 (blueprint docs/planning/16 §4e, keputusan §9 no. 3, berlaku 2026-11-17):
 * - Poin: +10 per level membaik, -10 per level memburuk, +2 bila tetap Terkendali (retensi).
 * - Bonus +5 DI ATAS poin perbaikan bila ada kunjungan tervalidasi (validation_status='valid')
 *   di antara dua pemeriksaan. Dulu kunjungan tervalidasi adalah SYARAT dihitung; akibatnya tidak
 *   satu pun transisi dihitung (belum pernah ada kunjungan tervalidasi).
 * - Hanya transisi karena PEMERIKSAAN LAB BARU (tanggal pemeriksaan lebih baru) yang dihitung
 *   (eligible). Hitung ulang aturan atas pemeriksaan yang sama (mis. revisi ambang 12 -> 17
 *   Agustus 2026, 5.002 transisi) tetap tercatat tetapi tidak menghasilkan poin peringkat.
 * - Poin masuk ke puskesmas pemilik pasien pada tanggal pemeriksaan itu (snapshot harian bila
 *   ada, selain itu puskesmas saat skor dihitung).
 * - Pemeriksaan pertama pasien tidak menghasilkan baris (tidak ada pembanding).
 * - Idempoten lewat UNIQUE current_risk_classification_id.
 */
class RiskTransitionScorer
{
    public const RULE_VERSION = 2;

    public const VISIT_BONUS_POINT = 5;

    private const RETENTION_POINT = 2;

    /**
     * @return int poin transisi tanpa bonus (bisa negatif)
     */
    public function point(string $previousLevel, string $currentLevel): int
    {
        if ($previousLevel === 'tidak_berisiko' && $currentLevel === 'tidak_berisiko') {
            return self::RETENTION_POINT;
        }

        return (RiskLevelScale::numeric($previousLevel) - RiskLevelScale::numeric($currentLevel)) * 10;
    }

    /**
     * @return RiskTransitionScore|null null kalau $previous null (pemeriksaan pertama pasien).
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

        return RiskTransitionScore::create([
            'patient_id' => $patient->id,
            'previous_risk_classification_id' => $previous->id,
            'current_risk_classification_id' => $current->id,
            'calculated_at' => now(),
            ...$this->hitung($patient, $previous, $current, $this->pemilikPada($patient, $current)),
        ]);
    }

    /**
     * Nilai skor menurut aturan v2. Dipakai untuk transisi baru DAN hitung ulang transisi lama
     * (produli:hitung-ulang-poin), supaya keduanya selalu sama.
     *
     * @return array<string, mixed>
     */
    public function hitung(PatientsCache $patient, RiskClassification $previous, RiskClassification $current, ?int $puskesmasId): array
    {
        $base = $this->point($previous->level, $current->level);
        $labBaru = $this->tanggalPemeriksaan($current)->gt($this->tanggalPemeriksaan($previous));
        $kunjungan = $this->findValidatedVisitBetween($patient, $previous, $current);
        $bonus = $kunjungan !== null && $base > 0 && $previous->level !== $current->level ? self::VISIT_BONUS_POINT : 0;

        return [
            'puskesmas_id' => $puskesmasId,
            'previous_risk_level' => $previous->level,
            'current_risk_level' => $current->level,
            'risk_delta' => RiskLevelScale::numeric($previous->level) - RiskLevelScale::numeric($current->level),
            'lab_driven' => $labBaru,
            'assessment_date' => $this->tanggalPemeriksaan($current)->toDateString(),
            'base_point' => $base,
            'visit_bonus_point' => $bonus,
            'final_point' => $base + $bonus,
            'related_validated_visit_id' => $kunjungan?->id,
            'eligible' => $labBaru,
            'rule_version' => self::RULE_VERSION,
        ];
    }

    /** Puskesmas pemilik pasien pada tanggal pemeriksaan (snapshot), selain itu saat ini. */
    public function pemilikPada(PatientsCache $patient, RiskClassification $current): ?int
    {
        $snapshot = PatientDailySnapshot::where('patients_cache_id', $patient->id)
            ->where('snapshot_date', $this->tanggalPemeriksaan($current)->toDateString())
            ->value('puskesmas_id');

        return $snapshot ?? $patient->puskesmas_id;
    }

    /**
     * Laporan kunjungan TERVALIDASI terbaru milik pasien ini di antara kedua pemeriksaan
     * (berdasar tanggal pemeriksaan, bukan tanggal hitung).
     */
    private function findValidatedVisitBetween(PatientsCache $patient, RiskClassification $previous, RiskClassification $current): ?VisitReport
    {
        $windowStart = $this->tanggalPemeriksaan($previous)->copy()->startOfDay();
        $windowEnd = $this->tanggalPemeriksaan($current)->copy()->endOfDay();

        if ($windowStart->gt($windowEnd)) {
            // Data lab bisa masuk tidak berurutan (delta sync SiLAKES): jendela dibalik.
            [$windowStart, $windowEnd] = [$windowEnd->copy()->startOfDay(), $windowStart->copy()->endOfDay()];
        }

        return VisitReport::query()
            ->whereHas('assignment', fn ($q) => $q->where('patient_id', $patient->id))
            ->where('validation_status', 'valid')
            ->whereBetween('created_at', [$windowStart, $windowEnd])
            ->orderByDesc('created_at')
            ->first();
    }

    private function tanggalPemeriksaan(RiskClassification $classification): CarbonInterface
    {
        return ($classification->assessment_date ?? $classification->computed_at)->copy()->startOfDay();
    }
}
