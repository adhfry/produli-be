<?php

namespace App\Support;

/**
 * HMAC-SHA256 NIK -- SAMA PERSIS dengan algoritme SiLAKES (api-administrasi-labkesda,
 * IntegrationController::hashNik(), docs/planning/04 §Endpoint 1: "HMAC-SHA256 hex, BUKAN NIK
 * asli, BUKAN hash polos"). KOPIPU tidak pernah menyimpan/menerima NIK asli dari SiLAKES sama
 * sekali -- kelas ini HANYA dipakai untuk meng-hash NIK yang diketik user saat mencari (exact
 * match hash-vs-hash), tidak pernah untuk membalik nik_hash tersimpan jadi NIK asli (mustahil,
 * HMAC satu arah).
 */
class NikHasher
{
    public static function hash(string $nik): string
    {
        $secret = (string) config('kopipu.silakes.nik_hash_secret');

        return hash_hmac('sha256', $nik, $secret);
    }
}
