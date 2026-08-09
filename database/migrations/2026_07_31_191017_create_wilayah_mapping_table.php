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
        Schema::create('wilayah_mapping', function (Blueprint $table) {
            $table->id();
            $table->string('kel_desa_raw', 150);
            $table->string('kecamatan_raw', 150)->nullable();
            $table->foreignId('desa_id')->nullable()->constrained('desa')->nullOnDelete();
            $table->enum('status', ['matched', 'unresolved'])->default('unresolved');
            $table->timestamp('matched_at')->nullable();
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['kel_desa_raw', 'kecamatan_raw'], 'uq_raw_text');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wilayah_mapping');
    }
};
