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
        Schema::create('patients_cache', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('external_patient_id')->unique();
            $table->string('no_reg', 50)->nullable();
            $table->string('nik_hash', 64)->index();
            $table->string('nama', 150);
            $table->string('gender', 1)->nullable();
            $table->date('tgl_lahir')->nullable();
            $table->string('phone', 20)->nullable();
            $table->text('alamat')->nullable();
            $table->string('rt_rw', 20)->nullable();
            $table->string('kel_desa_raw', 150)->nullable();
            $table->string('kecamatan_raw', 150)->nullable();
            $table->boolean('is_prolanis')->default(false);
            $table->string('jenis_prolanis', 50)->nullable();
            $table->boolean('is_perokok')->default(false);
            $table->string('jenis_perokok', 50)->nullable();

            // §2a — resolusi administratif desa/puskesmas.
            $table->foreignId('desa_id')->nullable()->constrained('desa')->nullOnDelete();
            $table->enum('wilayah_status', ['resolved', 'unresolved', 'unknown', 'out_of_scope'])
                ->default('unknown')
                ->index();
            $table->enum('puskesmas_resolution_method', ['desa', 'kecamatan_fallback', 'manual', 'kader_verified', 'unresolvable'])
                ->nullable();

            // §2c — presisi titik koordinat untuk GIS, independen dari wilayah_status.
            $table->enum('geo_status', ['unknown', 'approximate', 'verified'])->default('unknown');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->enum('geo_source', ['desa_centroid', 'patient_reported', 'kader_verified'])->nullable();
            $table->foreignId('geo_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('geo_verified_at')->nullable();

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patients_cache');
    }
};
