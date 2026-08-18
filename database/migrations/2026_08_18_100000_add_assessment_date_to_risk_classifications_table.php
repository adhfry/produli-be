<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risk_classifications', function (Blueprint $table) {
            // Tanggal pemeriksaan lab asli (dari SiLAKES, lab_results_cache.tanggal_periksa) yang
            // memicu klasifikasi ini -- BEDA dari computed_at (kapan sistem menghitung ulang).
            // Nullable karena baris lama (sebelum kolom ini ada) tidak bisa direkonstruksi penuh
            // tanpa backfill terpisah -- fallback ke computed_at tetap dipakai di query utk baris
            // yang assessment_date-nya null.
            $table->date('assessment_date')->nullable()->after('computed_at');
            $table->index('assessment_date');
        });
    }

    public function down(): void
    {
        Schema::table('risk_classifications', function (Blueprint $table) {
            $table->dropIndex(['assessment_date']);
            $table->dropColumn('assessment_date');
        });
    }
};
