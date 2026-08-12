<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rencana kunjungan BERULANG (revisi Bu Kadis) -- beda dari `visit_assignments` yang
 * merepresentasikan SATU kunjungan (one-shot). Satu `care_assignment` = satu pasangan
 * pasien+petugas yang punya tugas kunjungan berulang (kader mingguan mendampingi minum obat,
 * tenaga_kesehatan bulanan/2-mingguan pemeriksaan lanjutan). `visit_assignments` yang
 * di-generate dari sini (lihat kolom care_assignment_id di migration berikutnya) adalah
 * instance kunjungan individual yang dibuat otomatis (cadence_generated) atau manual mendesak
 * (adhoc), mengikuti rencana ini.
 *
 * SENGAJA TIDAK ada kolom cadence_days -- kadensi tenaga_kesehatan berubah dinamis mengikuti
 * level risiko pasien SAAT INI (berat = 14 hari, selain itu = 30 hari, dibaca langsung dari
 * risk_classifications.is_latest tiap kali cron scan jalan), bukan nilai tetap yang disimpan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients_cache')->cascadeOnDelete();
            $table->enum('worker_type', ['kader', 'tenaga_kesehatan']);
            $table->foreignId('kader_id')->nullable()->constrained('kader')->restrictOnDelete();
            $table->foreignId('tenaga_kesehatan_id')->nullable()->constrained('tenaga_kesehatan')->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            // Snapshot pattern sama seperti visit_assignments.puskesmas_id_snapshot -- immutable
            // di kode setelah create(), lihat docs/planning/02 §2a.
            $table->foreignId('puskesmas_id_snapshot')->constrained('puskesmas')->restrictOnDelete();
            $table->enum('status', ['active', 'paused', 'ended'])->default('active');
            // Anchor tanggal yang jadi acuan hitung due berikutnya -- di-update SETIAP kali
            // visit_assignments baru digenerate untuk plan ini (cadence_generated ATAU adhoc),
            // itulah mekanisme "kunjungan mendesak reset hitungan bulan depan" tanpa kode khusus.
            $table->date('last_triggered_at')->nullable();
            $table->date('last_completed_at')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'worker_type']);
            $table->index(['status', 'worker_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_assignments');
    }
};
