<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak "sudah dibaca" per user per pengumuman -- dipakai AnnouncementService::unreadForUser()
 * untuk gerbang modal inbox lebar saat login pertama (docs/planning: setiap user yang belum
 * dapat pengumuman TERBARU yang ditargetkan ke role-nya harus melihatnya sekali, lalu tidak lagi
 * begitu dibaca). Baris cuma ditulis saat user BENAR-BENAR menutup/mengonfirmasi pengumuman di
 * modal (AnnouncementController::markRead) -- bukan otomatis saat index() dipanggil, supaya
 * daftar di halaman /dashboard/pengumuman (yang juga hit index()) tidak diam-diam menandai
 * "sudah dibaca" tanpa user benar-benar melihat modalnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcement_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('announcement_id')->constrained('system_announcements')->cascadeOnDelete();
            $table->timestamp('read_at');

            $table->unique(['user_id', 'announcement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_reads');
    }
};
