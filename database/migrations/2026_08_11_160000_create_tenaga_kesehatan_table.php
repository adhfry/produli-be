<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profil "Tenaga Kesehatan" (revisi Bu Kadis) — peran baru yang melakukan pemeriksaan lebih
 * lanjut di rumah pasien (beda dari kader yang fokus pendampingan minum obat). Struktur SENGAJA
 * mirror persis tabel `kader` (lihat 2026_08_01_172427_create_kader_table.php) supaya pola
 * scoping puskesmas_id/DataScope yang sudah ada bisa dipakai ulang tanpa modifikasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenaga_kesehatan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('pj_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('puskesmas_id')->constrained('puskesmas')->restrictOnDelete();
            $table->boolean('status_aktif')->default(true);
            $table->string('no_hp', 20)->nullable();
            $table->string('no_wa', 20)->nullable();
            $table->text('alamat')->nullable();
            $table->string('gender', 1)->nullable();
            $table->date('tgl_lahir')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenaga_kesehatan');
    }
};
