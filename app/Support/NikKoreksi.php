<?php

namespace App\Support;

use App\Models\PatientsCache;

/**
 * Usulan NIK pasien lewat PRODULI (staf lewat propose-update, kader lewat laporan kunjungan).
 *
 * Revisi 2026-11-16 (permintaan pemilik sistem, temuan pasien 14029 ber-NIK "0000..."): NIK
 * BOLEH diajukan untuk SEMUA pasien, tidak lagi hanya yang NIK-nya kosong/"-"/berisi no. reg.
 * Usulan tetap masuk SiLAKES sebagai pending_review dan diverifikasi staf Labkesda. Yang
 * dijaga di sini: format NIK asli (NikParser) dan NIK tidak boleh sudah tercatat pada pasien
 * lain di PRODULI (SiLAKES juga menolak saat approve karena kolom NIK unik).
 */
final class NikKoreksi
{
    public const RULES = ['nullable', 'digits:16'];

    /** Dipertahankan untuk kompatibilitas field `nik_bisa_dikoreksi` di API (selalu true). */
    public static function bisaDiajukan(?string $nik, ?string $noReg): bool
    {
        return true;
    }

    public static function bisaDiajukanUntuk(?PatientsCache $patient): bool
    {
        return $patient !== null;
    }

    /**
     * Pesan galat bila NIK usulan tidak boleh dipakai untuk pasien ini, null bila boleh.
     */
    public static function galat(?PatientsCache $patient, string $nikBaru): ?string
    {
        if ($patient === null) {
            return 'Pasien tidak ditemukan.';
        }

        $parsed = NikParser::parse($nikBaru);
        if (! $parsed['valid']) {
            return $parsed['alasan'];
        }

        if ($patient->nik === $nikBaru) {
            return 'NIK ini sama dengan NIK pasien yang sekarang, tidak perlu diajukan.';
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
