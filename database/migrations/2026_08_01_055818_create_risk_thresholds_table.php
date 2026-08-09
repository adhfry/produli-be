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
        Schema::create('risk_thresholds', function (Blueprint $table) {
            $table->id();
            $table->string('parameter', 50);
            $table->enum('level', ['ringan', 'sedang', 'berat']);
            $table->enum('operator', ['>', '>=', '<', '<=', 'between']);
            $table->decimal('threshold_min', 10, 2)->nullable();
            $table->decimal('threshold_max', 10, 2)->nullable();
            $table->string('satuan', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->unique(['parameter', 'level']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('risk_thresholds');
    }
};
