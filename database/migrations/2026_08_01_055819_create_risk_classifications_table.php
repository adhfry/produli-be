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
        Schema::create('risk_classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients_cache')->cascadeOnDelete();
            $table->enum('level', ['ringan', 'sedang', 'berat']);
            $table->json('criteria_snapshot');
            $table->timestamp('computed_at');
            // Hindari subquery MAX(computed_at) di dashboard: hanya 1 baris is_latest=true per patient_id
            // (invariant dijaga oleh RiskClassificationService, bukan constraint DB).
            $table->boolean('is_latest')->default(true);
            $table->timestamps();

            $table->index(['patient_id', 'is_latest']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('risk_classifications');
    }
};
