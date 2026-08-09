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
        Schema::create('integration_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('service_name', 100);
            $table->string('endpoint', 100);
            $table->timestamp('requested_at');
            $table->enum('status', ['success', 'failed']);
            $table->unsignedInteger('records_count')->default(0);
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['service_name', 'status', 'requested_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integration_sync_logs');
    }
};
