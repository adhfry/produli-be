<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 blueprint docs/planning/16 §4c (2026-11-17): kondisi setiap pasien per hari, dibuat setelah
 * sinkron 02:00 (produli:snapshot-harian) dan disimpan selamanya (keputusan §9 no. 4).
 *
 * Menyimpan level risiko DAN puskesmas pemilik pada hari itu, supaya poin kinerja (Fase 5) masuk ke
 * puskesmas pemilik saat perubahan terjadi. Tanggal sebelum snapshot pertama tidak diisi mundur:
 * kondisinya direkonstruksi dari riwayat klasifikasi (tanggal pemeriksaan) dengan hasil yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_daily_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date');
            $table->foreignId('patients_cache_id')->constrained('patients_cache')->cascadeOnDelete();
            $table->foreignId('puskesmas_id')->nullable()->constrained('puskesmas')->nullOnDelete();
            $table->foreignId('kecamatan_id')->nullable()->constrained('kecamatan')->nullOnDelete();
            $table->foreignId('desa_id')->nullable()->constrained('desa')->nullOnDelete();
            $table->string('risk_level', 20)->nullable();
            $table->foreignId('risk_classification_id')->nullable()->constrained('risk_classifications')->nullOnDelete();
            $table->boolean('early_detection_flag')->default(false);
            $table->date('tanggal_lab_terakhir')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['snapshot_date', 'patients_cache_id'], 'pds_tanggal_pasien_unique');
            $table->index(['snapshot_date', 'puskesmas_id', 'risk_level'], 'pds_tanggal_puskesmas_risiko_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_daily_snapshots');
    }
};
