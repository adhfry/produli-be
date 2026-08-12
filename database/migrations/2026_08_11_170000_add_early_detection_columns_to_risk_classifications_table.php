<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Smart Early Detection" (revisi Bu Kadis) -- flag pasien Sedang yang pola/nilainya mendekati
 * ambang Berat, dihitung SEKALI saat RiskClassificationService::classify() jalan (bukan on-read
 * di setiap request dashboard/list pasien, biar tidak berat query). Lihat docblock
 * evaluateEarlyDetection() di RiskClassificationService untuk logika persisnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risk_classifications', function (Blueprint $table) {
            $table->boolean('early_detection_flag')->default(false)->after('level');
            $table->json('early_detection_reason')->nullable()->after('early_detection_flag');
        });
    }

    public function down(): void
    {
        Schema::table('risk_classifications', function (Blueprint $table) {
            $table->dropColumn(['early_detection_flag', 'early_detection_reason']);
        });
    }
};
