<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skor transisi risiko per pasien (audit trail append-only) -- dasar algoritma scoring kinerja
 * puskesmas (Top 5), lihat App\Services\Performance\RiskTransitionScorer. SATU baris per
 * transisi risk_classifications (baris lama -> baris baru), dibuat sekali saat
 * RiskClassificationService::classify() menulis baris baru DAN pasien sudah pernah punya
 * klasifikasi sebelumnya (assessment pertama pasien TIDAK menghasilkan baris di sini sama
 * sekali -- tidak ada pembanding, bukan "membaik").
 *
 * UNIQUE current_risk_classification_id = kunci idempotency: 1 baris klasifikasi baru cuma bisa
 * menghasilkan 1 baris skor, replay sync SiLAKES atau reclassify ulang tidak pernah dobel-hitung.
 *
 * puskesmas_id = puskesmas pasien SAAT skor ini dihitung (bukan snapshot historis) -- konsisten
 * dengan konvensi lama DashboardService::puskesmasPerformance() yang juga selalu memakai lokasi
 * TERKINI pasien walau datanya sendiri riwayat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_transition_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients_cache')->cascadeOnDelete();
            $table->foreignId('puskesmas_id')->nullable()->constrained('puskesmas')->nullOnDelete();
            $table->foreignId('previous_risk_classification_id')->constrained('risk_classifications')->cascadeOnDelete();
            $table->foreignId('current_risk_classification_id')->unique()->constrained('risk_classifications')->cascadeOnDelete();
            $table->enum('previous_risk_level', ['tidak_berisiko', 'ringan', 'sedang', 'berat']);
            $table->enum('current_risk_level', ['tidak_berisiko', 'ringan', 'sedang', 'berat']);
            // previous_numeric - current_numeric (Berat=3..Terkendali=0) -- positif = membaik.
            $table->smallInteger('risk_delta');
            $table->smallInteger('base_point');
            // Sama dengan base_point untuk sekarang (tidak ada lapisan penyesuaian lain) --
            // kolom terpisah disediakan sesuai spesifikasi audit trail, supaya ada tempat kalau
            // nanti perlu penyesuaian tanpa mengubah makna base_point historis.
            $table->smallInteger('final_point');
            // Bukti intervensi PRODULI yang menyertai transisi ini (WAJIB utk eligible=true) --
            // laporan kunjungan tervalidasi super_admin (validation_status='valid') milik pasien
            // yang sama, terjadi di antara assessment sebelumnya dan assessment ini.
            $table->foreignId('related_validated_visit_id')->nullable()->constrained('visit_reports')->nullOnDelete();
            // false = transisi TETAP tercatat (audit lengkap kenapa TIDAK dihitung), tapi tidak
            // masuk agregasi kinerja puskesmas -- tidak ada kunjungan tervalidasi yang membuktikan
            // ini hasil program, bukan cuma pasien yang kebetulan membaik.
            $table->boolean('eligible')->default(false);
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->index(['puskesmas_id', 'eligible']);
            $table->index(['patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_transition_scores');
    }
};
