<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi Bu Kadis (Fase 5, lanjutan): data nyata `pengirim` penuh rujukan PERORANGAN (dokter/
 * bidan, mis. "dr. Laos Susantina, SE", "Bidan Ika") -- bukan nama puskesmas sama sekali, jangan
 * pernah dipaksa cocok ke salah satu dari 31 puskesmas. Tier baru 'pengirim_individual' menandai
 * kasus ini secara eksplisit (beda dari 'unresolvable' yang berarti "dicoba, tidak ketemu apa
 * pun"). `pengirim_raw` menyimpan teks aslinya supaya staf bisa lihat "Rujukan: dr. X" di UI,
 * atau teks mentah untuk audit kasus unresolvable ("kenapa ini tidak teridentifikasi?").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients_cache', function (Blueprint $table) {
            $table->enum('puskesmas_resolution_method', ['desa', 'kecamatan_fallback', 'pengirim_matched', 'pengirim_individual', 'manual', 'kader_verified', 'unresolvable'])
                ->nullable()
                ->change();

            $table->string('pengirim_raw', 150)->nullable()->after('puskesmas_resolution_method');
        });
    }

    public function down(): void
    {
        Schema::table('patients_cache', function (Blueprint $table) {
            $table->dropColumn('pengirim_raw');

            $table->enum('puskesmas_resolution_method', ['desa', 'kecamatan_fallback', 'pengirim_matched', 'manual', 'kader_verified', 'unresolvable'])
                ->nullable()
                ->change();
        });
    }
};
