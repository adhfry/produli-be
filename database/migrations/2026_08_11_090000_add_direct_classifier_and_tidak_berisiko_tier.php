<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi Bu Kadis: Creatinine jadi "direct classifier" (bisa langsung menentukan level
 * sendirian lewat threshold bertingkat 1.7-1.9=sedang / >=2.0=berat, tanpa perlu 5
 * parameter lain ikut melebihi), dan tambah tier 'tidak_berisiko' supaya pasien yang
 * membaik total dari Berat/Sedang/Ringan bisa direpresentasikan (bukan diam-diam tetap
 * is_latest=true di level lama selamanya — lihat RiskClassificationService::classify()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risk_thresholds', function (Blueprint $table) {
            $table->boolean('is_direct_classifier')->default(false)->after('operator');
        });

        Schema::table('risk_thresholds', function (Blueprint $table) {
            $table->enum('level', ['tidak_berisiko', 'ringan', 'sedang', 'berat'])->change();
        });

        Schema::table('risk_classifications', function (Blueprint $table) {
            $table->enum('level', ['tidak_berisiko', 'ringan', 'sedang', 'berat'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('risk_thresholds', function (Blueprint $table) {
            $table->dropColumn('is_direct_classifier');
        });

        Schema::table('risk_thresholds', function (Blueprint $table) {
            $table->enum('level', ['ringan', 'sedang', 'berat'])->change();
        });

        Schema::table('risk_classifications', function (Blueprint $table) {
            $table->enum('level', ['ringan', 'sedang', 'berat'])->change();
        });
    }
};
