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
        Schema::table('visit_reports', function (Blueprint $table) {
            // Pemeriksaan saat kunjungan (docs/planning/02 §3 tabel visit_reports) -- SEMUA
            // nullable/opsional, kader isi apa yang sempat diperiksa di lapangan (bukan
            // pemeriksaan lab formal, jadi tidak masuk lab_results_cache/RiskClassificationService).
            $table->decimal('gda', 6, 2)->nullable(); // Gula Darah Acak
            $table->decimal('gdp', 6, 2)->nullable(); // Gula Darah Puasa
            $table->decimal('gd2jpp', 6, 2)->nullable(); // Gula Darah 2 Jam Post Prandial
            $table->decimal('uric_acid', 6, 2)->nullable();
            $table->decimal('cholesterol', 6, 2)->nullable();
            $table->unsignedSmallInteger('systolic')->nullable();
            $table->unsignedSmallInteger('diastolic')->nullable();
            $table->text('keluhan')->nullable();
            // Target kunjungan cuma pasien Berat -- hampir selalu salah satu dari 2 yang
            // pertama, 'tidak_ada' tetap disediakan untuk kunjungan yang tidak menghasilkan
            // tindakan medis apa pun.
            $table->enum('tindakan', ['diberi_obat', 'dirujuk_puskesmas', 'tidak_ada'])->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visit_reports', function (Blueprint $table) {
            $table->dropColumn([
                'gda', 'gdp', 'gd2jpp', 'uric_acid', 'cholesterol',
                'systolic', 'diastolic', 'keluhan', 'tindakan',
            ]);
        });
    }
};
