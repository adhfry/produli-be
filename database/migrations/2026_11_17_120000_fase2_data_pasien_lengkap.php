<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 blueprint docs/planning/16 §4b-§4d (2026-11-17).
 *
 * - patients_cache: identitas lengkap + domisili dari SiLAKES, waktu ubah di SiLAKES, kapan &
 *   lewat jalur apa PRODULI terakhir menarik pasien ini, dan kunci kepemilikan puskesmas.
 * - patient_sync_histories: riwayat perubahan per pasien (hanya ditulis bila ada yang berubah).
 * - Kunci kepemilikan (§9 no. 2): semua pasien yang SUDAH punya puskesmas langsung dikunci.
 *   Pemetaan aslinya (puskesmas_resolution_method) tidak diubah, jadi filter "ditetapkan lewat
 *   kecamatan" tetap bisa dipakai untuk meninjau.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients_cache', function (Blueprint $table) {
            $table->string('tempat_lahir', 150)->nullable()->after('tgl_lahir');
            $table->string('golongan_darah', 3)->nullable()->after('tempat_lahir');
            $table->string('agama', 50)->nullable()->after('golongan_darah');
            $table->string('status_perkawinan', 30)->nullable()->after('agama');
            $table->string('pekerjaan', 150)->nullable()->after('status_perkawinan');
            $table->boolean('is_bpjs')->nullable()->after('no_bpjs');
            $table->string('fktp', 255)->nullable()->after('is_bpjs');
            $table->string('email', 255)->nullable()->after('phone');

            $table->text('domisili_alamat')->nullable()->after('kecamatan_raw');
            $table->string('domisili_rt_rw', 20)->nullable()->after('domisili_alamat');
            $table->string('domisili_kel_desa', 150)->nullable()->after('domisili_rt_rw');
            $table->string('domisili_kecamatan', 150)->nullable()->after('domisili_kel_desa');
            $table->string('domisili_sumber', 50)->nullable()->after('domisili_kecamatan');
            $table->timestamp('domisili_updated_at')->nullable()->after('domisili_sumber');

            $table->timestamp('silakes_updated_at')->nullable()->after('last_synced_at');
            $table->timestamp('refreshed_at')->nullable()->after('silakes_updated_at');
            $table->string('refreshed_via', 20)->nullable()->after('refreshed_at');
            $table->timestamp('puskesmas_locked_at')->nullable()->after('puskesmas_resolution_method');
        });

        Schema::create('patient_sync_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patients_cache_id')->constrained('patients_cache')->cascadeOnDelete();
            $table->timestamp('synced_at');
            $table->string('sumber', 20); // harian | manual | realtime
            $table->string('silakes_event', 60)->nullable();
            $table->json('changed_fields'); // {kolom: {lama, baru}}
            $table->timestamp('created_at')->useCurrent();
            $table->index(['patients_cache_id', 'synced_at']);
        });

        // Nilai awal: waktu ubah di SiLAKES selama ini tersimpan di last_synced_at.
        DB::table('patients_cache')->whereNull('silakes_updated_at')->update(['silakes_updated_at' => DB::raw('last_synced_at')]);
        DB::table('patients_cache')->whereNotNull('puskesmas_id')->whereNull('puskesmas_locked_at')->update(['puskesmas_locked_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_sync_histories');

        Schema::table('patients_cache', function (Blueprint $table) {
            $table->dropColumn([
                'tempat_lahir', 'golongan_darah', 'agama', 'status_perkawinan', 'pekerjaan', 'is_bpjs', 'fktp', 'email',
                'domisili_alamat', 'domisili_rt_rw', 'domisili_kel_desa', 'domisili_kecamatan', 'domisili_sumber', 'domisili_updated_at',
                'silakes_updated_at', 'refreshed_at', 'refreshed_via', 'puskesmas_locked_at',
            ]);
        });
    }
};
