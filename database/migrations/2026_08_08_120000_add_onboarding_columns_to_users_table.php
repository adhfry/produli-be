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
            // Onboarding First-Login (docs/planning/02 §14).
            $table->timestamp('onboarding_completed_at')->nullable()->after('email_notifications_enabled');
            $table->timestamp('tos_accepted_at')->nullable()->after('onboarding_completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['onboarding_completed_at', 'tos_accepted_at']);
        });
    }
};
