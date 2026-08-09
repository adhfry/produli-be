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
            // Staf (admin_puskesmas/pj_prolanis) tidak punya tabel profil sendiri seperti kader
            // -- no_hp mereka disimpan langsung di sini (docs/planning/02, alur aktivasi akun).
            // Kader TETAP pakai kader.no_hp sendiri (tidak diduplikasi ke sini).
            $table->string('no_hp', 20)->nullable()->after('puskesmas_id');
            // Diset true saat akun diaktivasi lewat token (password random di-generate sistem)
            // -- frontend wajib redirect user ganti password sebelum lanjut pakai aplikasi.
            $table->boolean('must_change_password')->default(false)->after('no_hp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['no_hp', 'must_change_password']);
        });
    }
};
