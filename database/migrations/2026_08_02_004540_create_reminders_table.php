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
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('visit_assignments')->cascadeOnDelete();
            // 'push' = satu-satunya channel fungsional saat ini (DatabaseReminderChannel, lihat
            // app/Services/Notification) -- placeholder utk Web Push browser asli (docs/planning/02
            // §8) sampai infrastruktur VAPID/push_subscriptions/service worker PWA siap. 'wa' belum
            // diimplementasi (opsional tergantung anggaran gateway pihak ketiga, lihat §8).
            $table->string('channel', 20)->default('push');
            $table->timestamp('scheduled_at');
            $table->timestamp('sent_at')->nullable();
            $table->enum('status', ['pending', 'sent', 'failed', 'skipped'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            // Dedup: cegah reminder H-1/hari-H yang sama dibuat dobel kalau scheduler jalan
            // berkali-kali sebelum waktunya (lihat NotificationService::scheduleUpcomingReminders()).
            $table->unique(['assignment_id', 'channel', 'scheduled_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
