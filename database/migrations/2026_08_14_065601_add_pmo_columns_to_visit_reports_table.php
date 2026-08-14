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
            // Kunjungan mingguan PMO (Pendamping Minum Obat) kader -- BEDA dari kolom pemeriksaan
            // klinis (gda/gdp/dst, migration 2026_08_06_190703) yang jadi tanggung jawab tenaga_
            // kesehatan. Nullable/opsional seperti kolom pemeriksaan lain -- kader isi apa yang
            // sempat dicek saat kunjungan.
            $table->enum('kepatuhan_obat', ['patuh', 'kurang_patuh', 'tidak_patuh'])->nullable();
            $table->enum('sisa_obat', ['cukup', 'menipis', 'habis'])->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visit_reports', function (Blueprint $table) {
            $table->dropColumn(['kepatuhan_obat', 'sisa_obat']);
        });
    }
};
