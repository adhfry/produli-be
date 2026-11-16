<?php

namespace App\Support;

/**
 * Aturan isian data pasien yang dipakai bersama oleh form "Ajukan Update Data Pasien"
 * (ProposePatientUpdateRequest) dan form pasien baru di pengiriman sampel
 * (AddPatientToPengirimanSampelRequest). Nilai yang diterima disamakan dengan validasi SiLAKES
 * (registrasi pasien & endpoint integrasi), supaya data yang lolos di PRODULI tidak ditolak
 * lagi oleh SiLAKES setelah staf menekan kirim.
 */
final class PatientFormRules
{
    public const STATUS_PERKAWINAN = ['BELUM KAWIN', 'KAWIN', 'CERAI HIDUP', 'CERAI MATI'];

    public const GOLONGAN_DARAH = ['A', 'B', 'AB', 'O'];

    /** Nomor HP Indonesia: 08..., 628..., atau +628..., total 10-15 karakter (batas kolom SiLAKES 15). */
    public const PHONE_REGEX = '/^(\+62|62|0)8\d{7,11}$/';

    /**
     * @return array<string, array<int, string>>
     */
    public static function identitas(bool $required): array
    {
        $wajib = $required ? 'required' : 'nullable';

        return [
            'name' => [$wajib, 'string', 'min:3', 'max:255'],
            'tempat_lahir' => [$wajib, 'string', 'max:255'],
            'tgl_lahir' => [$wajib, 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            'gender' => [$wajib, 'in:L,P'],
            'alamat' => [$wajib, 'string', 'max:1000'],
            'phone' => [$wajib, 'string', 'max:15', 'regex:'.self::PHONE_REGEX],
            'rt_rw' => ['nullable', 'string', 'max:50'],
            'kel_desa' => ['nullable', 'string', 'max:255'],
            'kecamatan' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'pekerjaan' => ['nullable', 'string', 'max:255'],
            'status_perkawinan' => ['nullable', 'in:'.implode(',', self::STATUS_PERKAWINAN)],
            'golongan_darah' => ['nullable', 'in:'.implode(',', self::GOLONGAN_DARAH)],
            'agama' => ['nullable', 'string', 'max:50'],
            'no_bpjs' => ['nullable', 'string', 'regex:/^\d{13}$/'],
            'fktp' => ['nullable', 'string', 'max:255'],
            'jenis_prolanis' => ['nullable', 'in:DM,HT,DM_HT'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'phone.regex' => 'Nomor HP harus nomor Indonesia yang diawali 08, contoh 081234567890.',
            'no_bpjs.regex' => 'Nomor BPJS harus 13 digit angka.',
            'tgl_lahir.before' => 'Tanggal lahir harus sebelum hari ini.',
            'tgl_lahir.after' => 'Tanggal lahir tidak masuk akal.',
            'name.min' => 'Nama minimal 3 huruf.',
            'email.email' => 'Format email tidak benar.',
        ];
    }

    /**
     * Rapikan isian sebelum validasi: spasi di tepi dibuang, NIK/BPJS hanya angka, nomor HP tanpa
     * spasi/tanda hubung. String kosong jadi null supaya dianggap "tidak diisi".
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $hasil = [];
        foreach ($input as $key => $value) {
            if (! is_string($value)) {
                continue;
            }
            $value = trim($value);
            $value = match ($key) {
                'nik', 'no_bpjs' => preg_replace('/\D/', '', $value),
                'phone' => preg_replace('/[\s\-().]/', '', $value),
                default => $value,
            };
            $hasil[$key] = $value === '' ? null : $value;
        }

        return $hasil;
    }
}
