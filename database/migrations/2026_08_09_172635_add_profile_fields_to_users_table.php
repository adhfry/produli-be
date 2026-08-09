<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * Onboarding step "Lengkapi Profil" (docs/planning/02 §14) SEBELUMNYA cuma bisa menyimpan
     * data ini utk kader (tersimpan di tabel kader). admin_puskesmas/pj_prolanis tidak punya
     * kader row sama sekali, jadi tidak ada tempat menyimpan -- diperbaiki dengan menambah kolom
     * yang SAMA di users, dipakai KHUSUS staf non-kader (AuthController::completeOnboarding).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('no_wa', 20)->nullable()->after('no_hp');
            $table->text('alamat')->nullable()->after('no_wa');
            $table->enum('gender', ['L', 'P'])->nullable()->after('alamat');
            $table->date('tgl_lahir')->nullable()->after('gender');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['no_wa', 'alamat', 'gender', 'tgl_lahir']);
        });
    }
};
