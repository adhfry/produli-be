<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('visit_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('visit_assignments')->cascadeOnDelete();

            // Koordinat KADER saat submit (Layer 1 GPS aktif, docs/planning/02 §3) — beda dari
            // geo_status/latitude/longitude di bawah yang soal lokasi PASIEN.
            $table->decimal('gps_lat', 10, 7);
            $table->decimal('gps_lng', 10, 7);

            $table->string('photo_path'); // foto BER-WATERMARK (hasil WatermarkGenerator), bukan foto mentah
            $table->json('exif_meta')->nullable();
            $table->boolean('face_detected')->nullable(); // null kalau FaceDetectionCheck nonaktif (default)
            $table->string('kondisi');
            $table->text('catatan')->nullable();

            // Geo-verifikasi PASIEN (docs/planning/02 §2c) — snapshot hasil kunjungan INI, bukan
            // sekadar salinan patients_cache (itu current state, ini histori "apa yg terjadi saat
            // visit ini" — kalau kader konfirmasi "ini titik rumah pasien", 4 kolom ini terisi
            // DAN patients_cache ikut di-update oleh VisitReportService).
            $table->enum('geo_status', ['unknown', 'approximate', 'verified'])->default('unknown');
            $table->enum('geo_source', ['desa_centroid', 'patient_reported', 'kader_verified'])->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Status PUSH ke SiLAKES (pembaruan-lapangan) — BUKAN status approval staf Labkesda,
            // itu tidak di-poll KOPIPU (docs/planning/01 §9). Ini cuma "apa KOPIPU berhasil kirim usulan".
            $table->enum('sync_status', ['pending', 'synced', 'failed'])->default('pending');
            $table->text('sync_error')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->index('sync_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visit_reports');
    }
};
