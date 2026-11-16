<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Membaca tanggal lahir & jenis kelamin dari NIK, aturan SAMA dengan SiLAKES
 * (FE_SiLAKES/src/utils/nik.ts dan KtpOcrService::crossCheck): NIK 16 digit =
 * [6 kode wilayah][DDMMYY][4 urut], DD + 40 untuk perempuan. Nomor register pasien SiLAKES
 * diawali 1/2 (bukan kode provinsi), dan NIK pengganti "NIK tidak diketahui" diawali 0000 --
 * keduanya BUKAN NIK asli.
 */
final class NikParser
{
    /**
     * @return array{valid: bool, tgl_lahir?: string, gender?: string, alasan?: string}
     */
    public static function parse(?string $nik): array
    {
        $digits = preg_replace('/\D/', '', (string) $nik);

        if (strlen($digits) !== 16) {
            return ['valid' => false, 'alasan' => 'NIK harus 16 digit angka.'];
        }
        if (in_array($digits[0], ['1', '2'], true)) {
            return ['valid' => false, 'alasan' => 'Ini nomor registrasi pasien, bukan NIK KTP.'];
        }
        if ($digits[0] === '0') {
            return ['valid' => false, 'alasan' => 'NIK tidak boleh diawali 0. Periksa kembali KTP pasien.'];
        }

        $day = (int) substr($digits, 6, 2);
        $month = (int) substr($digits, 8, 2);
        $yy = (int) substr($digits, 10, 2);

        $gender = 'L';
        if ($day > 40) {
            $gender = 'P';
            $day -= 40;
        }

        $currentYy = (int) now()->format('y');
        $year = ($yy <= $currentYy ? 2000 : 1900) + $yy;

        if (! checkdate($month, $day, $year)) {
            return ['valid' => false, 'alasan' => 'Tanggal lahir di dalam NIK tidak masuk akal. Periksa kembali KTP pasien.'];
        }

        return [
            'valid' => true,
            'tgl_lahir' => sprintf('%04d-%02d-%02d', $year, $month, $day),
            'gender' => $gender,
        ];
    }

    /**
     * Ketidakcocokan NIK dengan tanggal lahir / jenis kelamin yang diisi. Kosong = cocok (atau
     * tidak ada yang bisa dibandingkan). Tahun dibandingkan 2 digit terakhir saja, sama seperti
     * SiLAKES, karena abad tidak tertulis di NIK.
     *
     * @return array<string, string> field => pesan
     */
    public static function mismatches(?string $nik, ?string $tglLahir, ?string $gender): array
    {
        $parsed = self::parse($nik);
        if (! $parsed['valid']) {
            return [];
        }

        $hasil = [];

        if ($tglLahir) {
            try {
                $dob = Carbon::parse($tglLahir);
                $dariNik = Carbon::parse($parsed['tgl_lahir']);
                if ($dob->day !== $dariNik->day || $dob->month !== $dariNik->month || $dob->year % 100 !== $dariNik->year % 100) {
                    $hasil['tgl_lahir'] = 'Tanggal lahir tidak sama dengan NIK (NIK menunjukkan '.$dariNik->format('d-m-').'..'.$dariNik->format('y').').';
                }
            } catch (\Throwable) {
                // format tanggal salah sudah ditolak aturan validasi tanggal biasa
            }
        }

        if ($gender && $gender !== $parsed['gender']) {
            $hasil['gender'] = 'Jenis kelamin tidak sama dengan NIK (NIK menunjukkan '.($parsed['gender'] === 'L' ? 'laki-laki' : 'perempuan').').';
        }

        return $hasil;
    }
}
