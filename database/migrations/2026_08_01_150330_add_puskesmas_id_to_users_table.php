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
        Schema::table('users', function (Blueprint $table) {
            // Scoping data per role (docs/planning/02 §7) — null untuk super_admin (semua data),
            // wajib diisi untuk admin_puskesmas/pj_prolanis/kader (dicek di Policy, bukan cuma role).
            $table->foreignId('puskesmas_id')->nullable()->after('google_id')
                ->constrained('puskesmas')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('puskesmas_id');
        });
    }
};
