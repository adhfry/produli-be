<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permintaan user -- penjadwalan otomatis kegiatan Prolanis berikutnya per pasien, dihitung
 * dari lab_results_cache.tanggal_periksa TERBARU (BUKAN created_at) + interval sesuai
 * jenis_prolanis (DM 3 bulan, HT 6 bulan, lihat config('produli.prolanis_schedule')).
 *
 * SATU baris AKTIF per pasien (unique patient_id) -- bukan riwayat berversi seperti
 * risk_classifications, cukup "jadwal SAAT INI" (pola lebih sederhana, sesuai kebutuhan
 * halaman kalender: "pasien mana dijadwalkan tanggal berapa", bukan audit trail historis).
 * is_manual_override menandai baris yang TANGGALNYA sudah diubah manual oleh staf puskesmas
 * (manajemen tanggal per puskesmas, permintaan user) -- ProlanisScheduleService::
 * generateSchedules() TIDAK PERNAH menimpa baris ber-flag ini saat lab baru masuk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prolanis_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients_cache')->cascadeOnDelete();
            $table->foreignId('puskesmas_id')->nullable()->constrained('puskesmas')->nullOnDelete();
            $table->string('jenis_prolanis', 10)->nullable();
            $table->date('source_lab_date')->nullable();
            $table->date('scheduled_date');
            $table->boolean('is_manual_override')->default(false);
            $table->enum('status', ['terjadwal', 'selesai', 'dibatalkan'])->default('terjadwal');
            $table->timestamp('notified_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('patient_id');
            $table->index(['puskesmas_id', 'scheduled_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prolanis_schedules');
    }
};
