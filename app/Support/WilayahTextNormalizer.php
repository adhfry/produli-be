<?php

namespace App\Support;

class WilayahTextNormalizer
{
    /**
     * Prefix administratif yang dibuang oleh normalizeRegionName() sebelum
     * dibandingkan, supaya "Kabupaten Sumenep" == "Sumenep" == "KAB. SUMENEP".
     * SiLAKES (tabel nusa) menyimpan nama kabupaten/kota dengan prefix ini,
     * sedangkan kecamatan/desa tidak (sudah dicek: "Ambunten", bukan
     * "Kecamatan Ambunten").
     */
    private const ADMINISTRATIVE_PREFIXES = [
        'KABUPATEN', 'KAB',
        'KOTA', 'KOT',
        'PROVINSI', 'PROV',
        'KECAMATAN', 'KEC',
        'KELURAHAN', 'KEL',
        'DESA',
    ];

    /**
     * Samakan huruf besar, buang semua karakter non-alfanumerik, baru bandingkan.
     * Contoh: "KALIMO'OK", "KALIMOOK", "KALIMO OK" -> semua jadi "KALIMOOK".
     *
     * Dipakai juga untuk mencocokkan header kolom CSV (mis. "DESA_KODE_KEMENDAGRI")
     * di ImportDesaPuskesmasCommand — JANGAN tambahkan logic pembuangan prefix
     * administratif di sini, itu akan merusak pencocokan header tsb. Untuk
     * mencocokkan NAMA WILAYAH (kabupaten/kota, dst.), pakai normalizeRegionName().
     *
     * Lihat docs/planning/02-arsitektur-backend-produli.md §2a.
     */
    public static function normalize(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim($text)));
    }

    /**
     * Sama seperti normalize(), plus membuang prefix administratif di depan
     * nama wilayah (KABUPATEN/KOTA/PROVINSI/KECAMATAN/KELURAHAN/DESA, dan
     * singkatannya) sebelum dibandingkan. Pakai ini untuk mencocokkan nama
     * kabupaten/kota/kecamatan/desa antar sumber data (mis. respons SiLAKES
     * vs teks bebas), BUKAN untuk mencocokkan header kolom/kunci lain.
     *
     * Contoh: "Kabupaten Sumenep" dan "Sumenep" -> sama-sama "SUMENEP".
     */
    public static function normalizeRegionName(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        $tokens = preg_split('/\s+/', mb_strtoupper(trim($text)), -1, PREG_SPLIT_NO_EMPTY);

        $cleanTokens = array_values(array_filter(
            array_map(fn (string $token) => preg_replace('/[^A-Z0-9]/', '', $token), $tokens),
            fn (string $token) => $token !== ''
        ));

        while ($cleanTokens !== [] && in_array($cleanTokens[0], self::ADMINISTRATIVE_PREFIXES, true)) {
            array_shift($cleanTokens);
        }

        return implode('', $cleanTokens);
    }

    /**
     * Prefix level-word yang aman dibuang PER LEVEL, dipakai oleh normalizeAdministrativeName().
     * Sengaja TIDAK menggabungkan semua prefix seperti normalizeRegionName() — di Sumenep ada
     * kecamatan yang nama resminya sendiri diawali "Kota" (Kota Sumenep), jadi membuang "KOTA"
     * saat mencocokkan level kecamatan/desa bisa merusak nama itu sendiri (jadi string kosong
     * untuk input seperti "KEC. KOTA") atau bikin tabrakan dengan "Sumenep" polos.
     */
    private const LEVEL_PREFIXES = [
        'kecamatan' => ['KECAMATAN', 'KEC'],
        'desa' => ['DESA', 'KELURAHAN', 'KEL'],
        // Dipakai mencocokkan surat_hasil_labs.pengirim (SiLAKES, teks bebas) ke puskesmas.nama
        // -- lihat WilayahResolver::matchPuskesmasByPengirim() (revisi Bu Kadis, Fase 5).
        'puskesmas' => ['PUSKESMAS', 'UPTD', 'UPT'],
    ];

    /**
     * Sama seperti normalizeRegionName(), tapi prefix yang dibuang di-scope per level
     * (lihat LEVEL_PREFIXES) — dipakai WilayahResolver untuk mencocokkan kecamatan/desa,
     * BUKAN untuk kabupaten/kota (tetap pakai normalizeRegionName() untuk itu).
     */
    public static function normalizeAdministrativeName(?string $text, string $level): string
    {
        if ($text === null) {
            return '';
        }

        $tokens = preg_split('/\s+/', mb_strtoupper(trim($text)), -1, PREG_SPLIT_NO_EMPTY);

        $cleanTokens = array_values(array_filter(
            array_map(fn (string $token) => preg_replace('/[^A-Z0-9]/', '', $token), $tokens),
            fn (string $token) => $token !== ''
        ));

        $prefixes = self::LEVEL_PREFIXES[$level] ?? [];

        while ($cleanTokens !== [] && in_array($cleanTokens[0], $prefixes, true)) {
            array_shift($cleanTokens);
        }

        return implode('', $cleanTokens);
    }
}
