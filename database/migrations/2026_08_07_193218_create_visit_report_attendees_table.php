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
        Schema::create('visit_report_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_report_id')->constrained('visit_reports')->cascadeOnDelete();
            $table->foreignId('kader_id')->constrained('kader')->cascadeOnDelete();
            // Cuma created_at (docs/planning/02 §16) -- ini kehadiran AKTUAL (siapa yang
            // benar-benar hadir), diisi saat kader submit laporan, pre-filled dari
            // visit_assignment_companions tapi bisa dikoreksi kader primer sebelum submit.
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['visit_report_id', 'kader_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visit_report_attendees');
    }
};
