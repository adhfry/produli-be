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
        // Cache lokal read-only dari SiLAKES reference_ranges (nilai rujukan terstruktur
        // umur+gender), ditarik via SilakesApiClient::referenceRanges() -- lihat
        // SyncSilakesService::syncReferenceRanges(). Cuma menampung 5 dari 12 parameter_key
        // SiLAKES yang dipakai RiskClassificationService (Creatinine SENGAJA tidak dipakai --
        // lihat App\Services\Risk\SilakesReferenceRangeService), tapi kolom mirror SEMUA
        // parameter_key yang datang supaya tidak perlu migrasi ulang kalau cakupan
        // RiskClassificationService bertambah nanti.
        Schema::create('reference_ranges_cache', function (Blueprint $table) {
            $table->id();
            $table->string('parameter_key');
            $table->char('gender', 1)->nullable();
            $table->decimal('age_min_years', 5, 2)->nullable();
            $table->decimal('age_max_years', 5, 2)->nullable();
            $table->unsignedInteger('age_min_days')->nullable();
            $table->unsignedInteger('age_max_days')->nullable();
            $table->decimal('value_min', 10, 3)->nullable();
            $table->decimal('value_max', 10, 3)->nullable();
            $table->boolean('min_inclusive')->default(true);
            $table->boolean('max_inclusive')->default(true);
            $table->string('category');
            $table->string('category_label');
            $table->unsignedTinyInteger('severity_rank')->default(0);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->index('parameter_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reference_ranges_cache');
    }
};
