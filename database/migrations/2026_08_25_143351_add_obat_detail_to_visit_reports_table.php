<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permintaan user: saat tindakan='diberi_obat', kader/nakes sekarang bisa merinci obat apa saja
 * yang diberikan (bisa >1 obat) -- nama, dosis, frekuensi per hari, per obat. `obat_detail` =
 * array JSON [{nama, dosis, frekuensi}, ...], nullable/additive -- tidak mempengaruhi baris lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_reports', function (Blueprint $table) {
            $table->json('obat_detail')->nullable()->after('tindakan');
        });
    }

    public function down(): void
    {
        Schema::table('visit_reports', function (Blueprint $table) {
            $table->dropColumn('obat_detail');
        });
    }
};
