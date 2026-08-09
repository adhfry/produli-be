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
        Schema::table('patients_cache', function (Blueprint $table) {
            // Diperlukan untuk kasus resolvePuskesmas() metode 'kecamatan_fallback'/'manual'/'kader_verified'
            // (docs/planning/02 §2b) — tidak selalu bisa diturunkan lewat join desa_id->puskesmas_id.
            $table->foreignId('puskesmas_id')->nullable()->after('wilayah_status')
                ->constrained('puskesmas')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patients_cache', function (Blueprint $table) {
            $table->dropConstrainedForeignId('puskesmas_id');
        });
    }
};
