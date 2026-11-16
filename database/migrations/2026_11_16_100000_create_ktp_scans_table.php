<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scan KTP pasien oleh staf puskesmas (revisi 2026-11-16): foto disimpan di PRODULI (disk
     * produli.ktp_photos_disk) DAN dikirim ke SiLAKES bersama usulannya. Satu baris = satu foto
     * yang diunggah, beserta hasil baca OCR (dibaca lewat SiLAKES, mesin yang sama dengan
     * registrasi SiLAKES). Kolom patients_cache_id / pengiriman_sampel_pasien_id terisi begitu
     * foto benar-benar dipakai untuk usulan, supaya foto bisa dilacak ke pasiennya.
     */
    public function up(): void
    {
        Schema::create('ktp_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('puskesmas_id')->nullable()->constrained('puskesmas')->nullOnDelete();
            $table->string('disk', 50);
            $table->string('path');
            $table->string('mime', 50);
            $table->unsignedInteger('size_bytes');
            $table->json('ocr_fields')->nullable();
            $table->json('ocr_checks')->nullable();
            $table->string('ocr_model', 100)->nullable();
            $table->string('ocr_error')->nullable();
            $table->foreignId('patients_cache_id')->nullable()->constrained('patients_cache')->nullOnDelete();
            $table->foreignId('pengiriman_sampel_pasien_id')->nullable()->constrained('pengiriman_sampel_pasien')->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ktp_scans');
    }
};
