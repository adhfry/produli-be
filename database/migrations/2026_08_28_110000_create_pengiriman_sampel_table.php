<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Pengiriman Sampel" -- batch antrian pasien Prolanis yang puskesmas susun untuk dikirim ke
 * Labkesda Sumenep (modul "Kirim Data Prolanis ke Labkesda Sumenep"). Status enum SUDAH memuat
 * seluruh siklus hidup penuh (draft -> terkunci -> ditugaskan -> otw -> tiba_labkesda ->
 * dikonfirmasi_labkesda, atau dibatalkan) meski Fase B (rilis awal) baru mengimplementasikan
 * transisi draft/terkunci/dibatalkan -- Fase C (kurir+GPS+foto) dan Fase D (sisi SiLAKES)
 * tinggal ISI kolom yang sudah disiapkan di sini, TIDAK perlu migration ALTER enum lagi.
 *
 * Kolom `foto_bukti_path`/GPS/`silakes_batch_ref`/`dikonfirmasi_labkesda_*` sengaja nullable
 * dan belum dipakai sampai Fase C/D -- disiapkan sekarang supaya bentuk tabel final tidak
 * berubah-ubah lagi begitu fase berikutnya jalan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengiriman_sampel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('puskesmas_id')->constrained('puskesmas')->restrictOnDelete();
            $table->enum('status', [
                'draft', 'terkunci', 'ditugaskan', 'otw',
                'tiba_labkesda', 'dikonfirmasi_labkesda', 'dibatalkan',
            ])->default('draft');

            $table->foreignId('dibuat_oleh')->constrained('users')->restrictOnDelete();
            $table->timestamp('dikunci_at')->nullable();
            $table->foreignId('dikunci_oleh')->nullable()->constrained('users')->nullOnDelete();

            // Fase C -- kurir & OTW.
            $table->foreignId('pengantar_sampel_id')->nullable()->constrained('pengantar_sampel')->restrictOnDelete();
            $table->timestamp('ditugaskan_at')->nullable();
            $table->foreignId('ditugaskan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('otw_at')->nullable();

            // Fase C -- bukti kedatangan (foto watermark + GPS), mirror kolom visit_reports.
            $table->timestamp('tiba_at')->nullable();
            $table->string('foto_bukti_path')->nullable();
            $table->decimal('tiba_gps_lat', 10, 7)->nullable();
            $table->decimal('tiba_gps_lng', 10, 7)->nullable();
            $table->decimal('tiba_gps_accuracy', 8, 2)->nullable();

            // Fase D -- hasil konfirmasi dari SiLAKES, ditulis balik lewat job polling.
            $table->timestamp('dikonfirmasi_labkesda_at')->nullable();
            $table->string('dikonfirmasi_labkesda_oleh')->nullable();
            $table->unsignedBigInteger('silakes_batch_ref')->nullable();

            $table->text('catatan')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['puskesmas_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengiriman_sampel');
    }
};
