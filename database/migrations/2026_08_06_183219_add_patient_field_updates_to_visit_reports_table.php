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
            // Usulan data pasien (kontak/alamat/identitas, docs/planning/01 §9) yang dilengkapi
            // kader "sembari menyelam minum air" saat kunjungan -- disimpan mentah di sini
            // (bukan dipecah jadi kolom, bukan diterapkan ke patients_cache) karena hasilnya
            // SELALU pending_review di SiLAKES, KOPIPU cuma relay + audit trail siapa yang
            // mengusulkan lewat laporan mana.
            $table->json('patient_field_updates')->nullable()->after('sync_error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visit_reports', function (Blueprint $table) {
            $table->dropColumn('patient_field_updates');
        });
    }
};
