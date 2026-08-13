<?php

namespace App\Support;

/**
 * HMAC-SHA256 NIK -- SAMA PERSIS dengan algoritme SiLAKES (api-administrasi-labkesda,
 * IntegrationController::hashNik(), docs/planning/04 §Endpoint 1). Kelas ini HANYA dipakai
 * untuk meng-hash NIK yang diketik user saat mencari (exact match hash-vs-hash di
 * PatientController::searchByNik()) -- tidak pernah untuk membalik nik_hash tersimpan jadi NIK
 * asli (mustahil secara matematis, HMAC satu arah). Sejak revisi Kepala Dinas, PRODULI JUGA
 * menyimpan NIK asli terpisah (kolom patients_cache.nik, dari SiLAKES, dipakai laporan PDF --
 * lihat App\Support\NikDisplay), tapi jalur pencarian ini sengaja tetap lewat hash, tidak diubah.
 */
class NikHasher
{
    public static function hash(string $nik): string
    {
        $secret = (string) config('produli.silakes.nik_hash_secret');

        return hash_hmac('sha256', $nik, $secret);
    }
}
