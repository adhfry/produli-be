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
        Schema::table('visit_assignments', function (Blueprint $table) {
            // Jalur alternatif untuk pasien risk_level=Berat yang wilayahnya TIDAK resolved
            // (bukan resolved/kecamatan_fallback) tapi punya nomor telepon -- kader diarahkan
            // hubungi dulu lewat telepon (bukan lewat peta) lalu melengkapi alamat saat
            // kunjungan. 'wilayah_resolved' = jalur normal (default, tidak ubah data lama).
            $table->enum('assignment_method', ['wilayah_resolved', 'phone_contact'])
                ->default('wilayah_resolved')
                ->after('priority');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visit_assignments', function (Blueprint $table) {
            $table->dropColumn('assignment_method');
        });
    }
};
