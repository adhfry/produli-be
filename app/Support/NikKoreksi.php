<?php

namespace App\Support;

use App\Models\PatientsCache;

/**
 * Koreksi NIK lewat usulan pembaruan data (permintaan Kepala Dinas 2026-10-13): pasien yang NIK-nya
 * di SiLAKES tidak terisi dengan benar -- kosong, "-", atau berisi nomor registrasi (petugas
 * pendaftaran dulu mengisi kolom NIK dengan no. reg) -- boleh diajukan NIK 16 digit yang benar,
 * baik oleh staf (propose-update) maupun kader (laporan kunjungan). Pasien yang NIK-nya sudah
 * terisi lain dari itu TIDAK bisa diajukan ganti NIK dari PRODULI (identitas inti, dikoreksi di
 * SiLAKES). Usulan tetap masuk SiLAKES sebagai pending_review seperti field lain.
 */
final class NikKoreksi
{
    public const RULES = ['nullable', 'digits:16'];

    public static function bisaDiajukan(?string $nik, ?string $noReg): bool
    {
        $nik = trim((string) $nik);

        if ($nik === '' || trim($nik, "- \t") === '') {
            return true; // kosong / "-" / "--"
        }

        $noReg = trim((string) $noReg);

        return $noReg !== '' && str_contains(mb_strtoupper($nik), mb_strtoupper($noReg));
    }

    public static function bisaDiajukanUntuk(?PatientsCache $patient): bool
    {
        return $patient !== null && self::bisaDiajukan($patient->nik, $patient->no_reg);
    }

    /**
     * Pesan galat bila NIK usulan tidak boleh dipakai untuk pasien ini, null bila boleh.
     */
    public static function galat(?PatientsCache $patient, string $nikBaru): ?string
    {
        if (! self::bisaDiajukanUntuk($patient)) {
            return 'NIK pasien ini sudah terisi. Koreksi NIK lewat PRODULI hanya untuk pasien yang NIK-nya kosong, "-", atau berisi nomor registrasi.';
        }

        $dipakai = PatientsCache::query()
            ->whereKeyNot($patient->getKey())
            ->where(fn ($q) => $q->where('nik', $nikBaru)->orWhere('nik_hash', NikHasher::hash($nikBaru)))
            ->exists();

        return $dipakai
            ? 'NIK ini sudah tercatat pada pasien lain. Periksa kembali KTP pasien atau hubungi Labkesda bila data ganda.'
            : null;
    }
}
