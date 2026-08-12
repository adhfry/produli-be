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
        Schema::create('lab_results_cache', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('external_id'); // lab_result_id di SiLAKES
            // Merujuk patients_cache.external_patient_id secara longgar (bukan FK constraint)
            // karena lab-results & patients disinkron dari 2 endpoint/cursor independen (docs/planning/04).
            $table->unsignedBigInteger('patient_id')->index();
            $table->string('parameter', 50);
            $table->string('value', 50); // "hasil" — string, bukan number (docs/planning/01 §Catatan Implementasi Wajib)
            $table->string('satuan', 20)->nullable();
            $table->string('nilai_rujukan', 50)->nullable(); // standar lab SiLAKES, BUKAN threshold risiko PRODULI
            $table->string('class_hasil', 50)->nullable();
            $table->string('validation_status', 30)->nullable();
            $table->date('tanggal_periksa');
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['external_id', 'parameter']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lab_results_cache');
    }
};
