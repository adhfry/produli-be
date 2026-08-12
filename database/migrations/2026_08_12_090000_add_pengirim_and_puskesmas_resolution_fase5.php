<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi Bu Kadis (Fase 5): expose kolom `pengirim` (surat_hasil_labs.pengirim, docs/planning/04
 * §Endpoint 2) di cache lokal, dan tambah tier 'pengirim_matched' ke
 * patients_cache.puskesmas_resolution_method -- fallback TERAKHIR (dicoba SETELAH desa/
 * kecamatan_fallback gagal) yang mencocokkan teks bebas `pengirim` ke puskesmas.nama, lihat
 * WilayahResolver::resolvePuskesmas().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_results_cache', function (Blueprint $table) {
            $table->string('pengirim', 150)->nullable()->after('validation_status');
        });

        Schema::table('patients_cache', function (Blueprint $table) {
            $table->enum('puskesmas_resolution_method', ['desa', 'kecamatan_fallback', 'pengirim_matched', 'manual', 'kader_verified', 'unresolvable'])
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('lab_results_cache', function (Blueprint $table) {
            $table->dropColumn('pengirim');
        });

        Schema::table('patients_cache', function (Blueprint $table) {
            $table->enum('puskesmas_resolution_method', ['desa', 'kecamatan_fallback', 'manual', 'kader_verified', 'unresolvable'])
                ->nullable()
                ->change();
        });
    }
};
