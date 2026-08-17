<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No BPJS pasien -- revisi Bu Kadis: staf labkesda terkadang copas no_reg ke kolom no_bpjs saat
 * input manual di SiLAKES, jadi frontend perlu bisa tandai kasus itu (no_bpjs === no_reg).
 * SiLAKES kini mengirim field `no_bpjs` di response sync (lihat docs/planning/04 di repo
 * SiLAKES), dikonsumsi SyncSilakesService::upsertPatient().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients_cache', function (Blueprint $table) {
            $table->string('no_bpjs', 50)->nullable()->after('no_reg');
        });
    }

    public function down(): void
    {
        Schema::table('patients_cache', function (Blueprint $table) {
            $table->dropColumn('no_bpjs');
        });
    }
};
