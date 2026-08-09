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
        Schema::create('visit_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients_cache')->cascadeOnDelete();
            $table->foreignId('kader_id')->constrained('kader')->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('scheduled_date');
            $table->enum('status', ['pending', 'in_progress', 'completed', 'cancelled'])->default('pending');
            $table->enum('priority', ['ringan', 'sedang', 'berat']);
            // Snapshot puskesmas SAAT assignment dibuat — immutable setelahnya (di kode, bukan DB
            // trigger), supaya laporan historis tidak berubah kalau desa.puskesmas_id direassign
            // di kemudian hari (docs/planning/02 §2a).
            $table->foreignId('puskesmas_id_snapshot')->constrained('puskesmas')->restrictOnDelete();
            $table->timestamps();

            $table->index(['kader_id', 'scheduled_date']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visit_assignments');
    }
};
