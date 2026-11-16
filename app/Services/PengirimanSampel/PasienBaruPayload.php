<?php

namespace App\Services\PengirimanSampel;

use App\Models\PengirimanSampelPasien;
use App\Services\Ktp\KtpScanService;

/**
 * Identitas pasien baru dari satu baris antrian, dalam bentuk yang diterima SiLAKES. Satu sumber
 * untuk pendaftaran langsung (RegisterPasienBaruToSilakesJob) dan pengiriman batch saat sampel
 * tiba (SendProlanisDeliveryToSilakesJob), supaya keduanya selalu mengirim isian yang sama.
 */
class PasienBaruPayload
{
    /** @return array<string, mixed> */
    public static function untuk(PengirimanSampelPasien $pasien): array
    {
        return [
            'name' => $pasien->nama_snapshot,
            'nik' => $pasien->data_pasien_baru_nik,
            'gender' => $pasien->data_pasien_baru_gender,
            'tempat_lahir' => $pasien->data_pasien_baru_tempat_lahir,
            'tgl_lahir' => $pasien->data_pasien_baru_tgl_lahir?->format('Y-m-d'),
            'phone' => $pasien->data_pasien_baru_phone,
            'alamat' => $pasien->data_pasien_baru_alamat,
            'rt_rw' => $pasien->data_pasien_baru_rt_rw,
            'kel_desa' => $pasien->data_pasien_baru_kel_desa,
            'kecamatan' => $pasien->data_pasien_baru_kecamatan,
            'no_bpjs' => $pasien->data_pasien_baru_no_bpjs,
            'jenis_prolanis' => $pasien->jenis_prolanis_snapshot,
            // Revisi 2026-11-16: identitas lengkap + foto KTP (dibaca saat job jalan, bukan
            // disimpan di antrian). Kunci null dibuang supaya batch lama tetap identik.
            ...array_filter([
                'email' => $pasien->data_pasien_baru_email,
                'agama' => $pasien->data_pasien_baru_agama,
                'status_perkawinan' => $pasien->data_pasien_baru_status_perkawinan,
                'pekerjaan' => $pasien->data_pasien_baru_pekerjaan,
                'golongan_darah' => $pasien->data_pasien_baru_golongan_darah,
                'fktp' => $pasien->data_pasien_baru_fktp,
                'foto_ktp_base64' => app(KtpScanService::class)->base64Of($pasien->ktp_scan_id),
            ], fn ($v) => $v !== null),
            'produli_pengiriman_sampel_pasien_id' => $pasien->id,
        ];
    }
}
