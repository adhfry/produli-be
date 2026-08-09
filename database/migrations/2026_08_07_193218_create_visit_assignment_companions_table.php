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
        Schema::create('visit_assignment_companions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('visit_assignments')->cascadeOnDelete();
            $table->foreignId('kader_id')->constrained('kader')->cascadeOnDelete();
            // Cuma created_at (docs/planning/02 §16) -- ini rencana ("siapa akan ikut"),
            // dibuat sekali saat POST .../bulk, tidak pernah di-update setelahnya.
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['assignment_id', 'kader_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visit_assignment_companions');
    }
};
