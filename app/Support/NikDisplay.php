<?php

namespace App\Support;

/**
 * Aturan tampilan NIK di laporan PDF (permintaan Kepala Dinas) -- SATU-SATUNYA tempat NIK asli
 * dari patients_cache boleh dirender ke output manusia. "3529" adalah kode wilayah Kabupaten
 * Sumenep; NIK yang tidak diawali kode itu kemungkinan besar data tidak valid/pasien luar
 * daerah yang salah tersinkron -- ditampilkan "Tidak Diketahui" alih-alih NIK mentah yang
 * meragukan. Dipakai HANYA di jalur PDF (resources/views/pdf/patients-export.blade.php),
 * TIDAK PERNAH dari PatientResource (API JSON dashboard) -- lihat PatientResource docblock.
 */
class NikDisplay
{
    private const KODE_WILAYAH_SUMENEP = '3529';

    public static function resolve(?string $nik): string
    {
        if ($nik !== null && str_starts_with($nik, self::KODE_WILAYAH_SUMENEP)) {
            return $nik;
        }

        return 'Tidak Diketahui';
    }
}
