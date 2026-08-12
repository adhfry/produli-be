<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device token FCM (Firebase Cloud Messaging) per user -- satu user bisa punya beberapa token
 * aktif sekaligus (login dari beberapa browser/device PWA). Token FCM web bisa berubah
 * (di-refresh browser) -- unique di kolom token sendiri (bukan per user) supaya re-registrasi
 * token yang sama dari user manapun otomatis idempotent (updateOrCreate berdasar token).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fcm_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 255)->unique();
            $table->string('device_label', 100)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fcm_tokens');
    }
};
