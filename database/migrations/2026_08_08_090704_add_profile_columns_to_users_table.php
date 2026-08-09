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
            // Halaman Profil Saya & Pengaturan (docs/planning/02 §17).
            $table->string('avatar_path')->nullable()->after('google_id');
            $table->boolean('email_notifications_enabled')->default(true)->after('must_change_password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_path', 'email_notifications_enabled']);
        });
    }
};
