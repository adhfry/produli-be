<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Skala numerik risk_classifications.level KHUSUS untuk algoritma scoring kinerja puskesmas
 * (App\Services\Performance\RiskTransitionScorer) -- TIDAK mengubah/menambah enum
 * risk_classifications.level yang sudah ada (tetap 'tidak_berisiko'/'ringan'/'sedang'/'berat').
 * "Terkendali" di sini murni label tampilan untuk 'tidak_berisiko' KHUSUS di konteks scoring
 * (permintaan eksplisit user) -- di luar modul ini level itu tetap disebut "Tidak Berisiko"
 * (lihat RiskClassificationResource/dashboard existing), jangan dipakai gonta-ganti label lain.
 *
 * Semakin KECIL angka = semakin BAIK (Terkendali=0 paling ringan, Berat=3 paling parah) --
 * kebalikan dari RiskClassificationService::SEVERITY_ORDER (indeks array, kecil=ringan tapi
 * 'tidak_berisiko' ada di indeks 0 juga di sana -- KEBETULAN nilainya sama, TAPI dua konsep
 * yang beda: SEVERITY_ORDER itu urutan array biasa, RiskLevelScale ini skala poin utk aritmatika
 * (previous - current) * 10 pada RiskTransitionScorer). Jangan saling menggantikan keduanya.
 */
class RiskLevelScale
{
    private const NUMERIC = [
        'berat' => 3,
        'sedang' => 2,
        'ringan' => 1,
        'tidak_berisiko' => 0,
    ];

    private const SCORING_LABELS = [
        'berat' => 'Berat',
        'sedang' => 'Sedang',
        'ringan' => 'Ringan',
        'tidak_berisiko' => 'Terkendali',
    ];

    public static function numeric(string $level): int
    {
        return self::NUMERIC[$level]
            ?? throw new InvalidArgumentException("RiskLevelScale: level risiko tidak dikenal '{$level}'.");
    }

    /** Label KHUSUS konteks scoring (lihat docblock kelas) -- bukan untuk dipakai di luar modul ini. */
    public static function scoringLabel(string $level): string
    {
        return self::SCORING_LABELS[$level] ?? $level;
    }
}
