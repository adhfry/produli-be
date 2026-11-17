<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5 blueprint docs/planning/16 §4e (2026-11-17), aturan poin v2 (keputusan §9 no. 3):
 *
 * - lab_driven: transisi terjadi karena PEMERIKSAAN LAB BARU (tanggal pemeriksaan naik). Hanya ini
 *   yang dihitung (eligible); hitung ulang aturan atas pemeriksaan yang sama tidak menghasilkan poin.
 * - visit_bonus_point: +5 di atas poin perbaikan bila ada kunjungan tervalidasi di antara dua
 *   pemeriksaan (dulu kunjungan tervalidasi adalah SYARAT; kini bonus).
 * - assessment_date: tanggal pemeriksaan transisi, dasar filter periode (bukan calculated_at).
 * - rule_version: 1 = aturan lama, 2 = aturan ini. Baris lama dihitung ulang di tempat
 *   (produli:hitung-ulang-poin), tidak dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risk_transition_scores', function (Blueprint $table) {
            $table->boolean('lab_driven')->default(false)->after('risk_delta');
            $table->smallInteger('visit_bonus_point')->default(0)->after('base_point');
            $table->date('assessment_date')->nullable()->after('lab_driven');
            $table->unsignedTinyInteger('rule_version')->default(1)->after('eligible');
            $table->index(['eligible', 'assessment_date', 'puskesmas_id'], 'rts_eligible_tanggal_puskesmas_idx');
        });
    }

    public function down(): void
    {
        Schema::table('risk_transition_scores', function (Blueprint $table) {
            $table->dropIndex('rts_eligible_tanggal_puskesmas_idx');
            $table->dropColumn(['lab_driven', 'visit_bonus_point', 'assessment_date', 'rule_version']);
        });
    }
};
