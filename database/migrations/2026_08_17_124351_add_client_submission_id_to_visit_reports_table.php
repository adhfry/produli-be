<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('visit_reports', function (Blueprint $table) {
            // Temuan audit (docs/planning/15): OfflineQueueHandler (Layer 7) SUDAH mewajibkan
            // client_submission_id untuk submission offline sejak awal, tapi kolom ini belum ada
            // di tabel -- deteksi duplikat retry (kader di sinyal lemah menekan "Kirim" lalu
            // antrean offline mengirim ulang) tidak pernah benar-benar dicek ke DB. unique index
            // MySQL mengizinkan banyak NULL (submission ONLINE tidak mengirim field ini sama
            // sekali) -- cuma menolak client_submission_id yang SAMA persis dua kali, jadi jadi
            // jaring pengaman terakhir kalau dua request submit BENAR-BENAR bersamaan lolos dari
            // pengecekan aplikasi (lihat VisitReportService::submit()).
            $table->string('client_submission_id', 100)->nullable()->after('assignment_id');
            $table->unique('client_submission_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visit_reports', function (Blueprint $table) {
            $table->dropUnique(['client_submission_id']);
            $table->dropColumn('client_submission_id');
        });
    }
};
