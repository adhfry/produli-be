<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi 2026-11-17 (docs/planning/16 §12):
 *
 * 1. Pasien baru di antrian pengiriman sampel didaftarkan ke SiLAKES SAAT DIISI, bukan menunggu
 *    sampel tiba. Status pendaftarannya disimpan per baris; id usulannya memakai kolom lama
 *    registration_proposal_ref. Setelah disetujui, baris ditautkan ke pasien (external_patient_id).
 * 2. SiLAKES mendorong hasil persetujuan ke PRODULI (POST /integration/silakes-events). Setiap
 *    event punya event_id; silakes_event_receipts mencegah event yang sama diproses dua kali.
 * 3. silakes_field_update_refs memetakan id usulan perubahan data di SiLAKES ke staf PRODULI yang
 *    mengajukannya, supaya keputusan staf Labkesda bisa dinotifikasikan ke orang yang tepat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengiriman_sampel_pasien', function (Blueprint $table) {
            $table->foreignId('diinput_oleh_user_id')->nullable()->after('ktp_scan_id')->constrained('users')->nullOnDelete();
            // mengirim | menunggu_verifikasi | terdaftar | ditolak | gagal_kirim (null = pasien lama)
            $table->string('registrasi_status', 30)->nullable()->after('diinput_oleh_user_id');
            $table->text('registrasi_catatan')->nullable()->after('registrasi_status');
            $table->string('registrasi_no_reg', 50)->nullable()->after('registrasi_catatan');
            $table->timestamp('registrasi_diperbarui_at')->nullable()->after('registrasi_no_reg');
            $table->index('registration_proposal_ref');
            $table->index('registrasi_status');
        });

        Schema::create('silakes_event_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->string('event', 60);
            $table->timestamp('received_at');
        });

        Schema::create('silakes_field_update_refs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('silakes_update_id')->unique();
            $table->uuid('usulan_id')->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('patients_cache_id')->constrained('patients_cache')->cascadeOnDelete();
            $table->string('status', 20)->default('pending_review');
            $table->text('catatan_reviewer')->nullable();
            $table->timestamp('diputuskan_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('silakes_field_update_refs');
        Schema::dropIfExists('silakes_event_receipts');

        Schema::table('pengiriman_sampel_pasien', function (Blueprint $table) {
            $table->dropIndex(['registrasi_status']);
            $table->dropIndex(['registration_proposal_ref']);
            $table->dropConstrainedForeignId('diinput_oleh_user_id');
            $table->dropColumn(['registrasi_status', 'registrasi_catatan', 'registrasi_no_reg', 'registrasi_diperbarui_at']);
        });
    }
};
