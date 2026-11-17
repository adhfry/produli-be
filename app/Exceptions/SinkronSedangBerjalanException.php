<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Sinkron SiLAKES lain (jadwal 02:00, tombol manual, atau produli:isi-ulang-pasien) masih memegang
 * kunci bersama SyncSilakesService::KUNCI. Pemanggil cukup mencoba lagi nanti; tidak ada data
 * yang berubah dan tidak dicatat sebagai sinkron gagal.
 */
class SinkronSedangBerjalanException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Sinkronisasi SiLAKES lain sedang berjalan. Coba lagi beberapa menit lagi.');
    }
}
