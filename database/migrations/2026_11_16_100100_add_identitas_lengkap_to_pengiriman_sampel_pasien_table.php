<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Revisi 2026-11-16: pasien baru di antrian pengiriman sampel diisi lengkap seperti
     * registrasi SiLAKES (agama, status perkawinan, pekerjaan, golongan darah, email, FKTP) plus
     * foto KTP. Semua nullable: baris lama tetap sah, kolom wajib lama tidak berubah.
     */
    public function up(): void
    {
        Schema::table('pengiriman_sampel_pasien', function (Blueprint $table) {
            $table->string('data_pasien_baru_email')->nullable()->after('data_pasien_baru_no_bpjs');
            $table->string('data_pasien_baru_agama', 50)->nullable()->after('data_pasien_baru_email');
            $table->string('data_pasien_baru_status_perkawinan', 50)->nullable()->after('data_pasien_baru_agama');
            $table->string('data_pasien_baru_pekerjaan')->nullable()->after('data_pasien_baru_status_perkawinan');
            $table->string('data_pasien_baru_golongan_darah', 5)->nullable()->after('data_pasien_baru_pekerjaan');
            $table->string('data_pasien_baru_fktp')->nullable()->after('data_pasien_baru_golongan_darah');
            $table->unsignedBigInteger('ktp_scan_id')->nullable()->after('data_pasien_baru_fktp');
        });
    }

    public function down(): void
    {
        Schema::table('pengiriman_sampel_pasien', function (Blueprint $table) {
            $table->dropColumn([
                'data_pasien_baru_email', 'data_pasien_baru_agama', 'data_pasien_baru_status_perkawinan',
                'data_pasien_baru_pekerjaan', 'data_pasien_baru_golongan_darah', 'data_pasien_baru_fktp', 'ktp_scan_id',
            ]);
        });
    }
};
