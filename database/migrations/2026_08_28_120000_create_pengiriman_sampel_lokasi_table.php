<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Posisi TERKINI kurir yang sedang OTW ke Labkesda (Fase C) -- SATU baris per
 * `pengiriman_sampel` (unique, `updateOrCreate`), BUKAN log tiap ping GPS. Ini konsekuensi
 * langsung dari keputusan desain "broadcast realtime cuma sinyal, bukan pembawa data" (lihat
 * RealtimeBroadcastService): HP kurir POST lokasi tiap ~20-30 detik, PRODULI cuma broadcast
 * SINYAL 'lokasi berubah' ke peta super_admin, peta lalu fetch REST baris TERKINI ini --
 * riwayat titik-per-titik tidak dibutuhkan sama sekali, jadi tidak disimpan (irit storage,
 * tidak perlu retention job).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengiriman_sampel_lokasi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pengiriman_sampel_id')->unique()->constrained('pengiriman_sampel')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy', 8, 2)->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengiriman_sampel_lokasi');
    }
};
