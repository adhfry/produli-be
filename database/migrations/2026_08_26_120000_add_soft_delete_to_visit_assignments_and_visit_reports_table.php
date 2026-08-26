<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fitur "Hapus Kunjungan" (permintaan user, takut ada kunjungan yang BENAR-BENAR salah --
 * beda dari "Batalkan" yang sudah ada, itu cuma ubah status jadi 'cancelled' tapi barisnya
 * tetap tampil selamanya di riwayat/monitoring). deleted_at (BUKAN hard delete) -- data
 * kesehatan tidak boleh lenyap tanpa jejak sama sekali, super_admin masih perlu bisa
 * menelusuri kenapa suatu kunjungan dihapus kalau suatu saat dipertanyakan.
 *
 * deletion_reason WAJIB diisi saat hapus (divalidasi di DeleteVisitAssignmentRequest, bukan
 * di kolom DB -- nullable di sini supaya baris LAMA yang tidak pernah dihapus tetap valid).
 *
 * visit_reports IKUT deleted_at (cascade manual di VisitAssignmentService::softDelete(),
 * BUKAN foreign key cascade DB) -- beberapa query lain (KaderController::visitHistory(),
 * TenagaKesehatanController, RujukanService, RiskTransitionScorer) mengambil VisitReport
 * LANGSUNG tanpa lewat VisitAssignment, kalau reportnya tidak ikut soft-delete, laporan dari
 * kunjungan yang sudah "dihapus" tetap muncul di tempat-tempat itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_assignments', function (Blueprint $table) {
            $table->softDeletes();
            $table->text('deletion_reason')->nullable()->after('deleted_at');
            $table->foreignId('deleted_by')->nullable()->after('deletion_reason')->constrained('users')->nullOnDelete();
        });

        Schema::table('visit_reports', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('visit_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropColumn('deletion_reason');
            $table->dropSoftDeletes();
        });

        Schema::table('visit_reports', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
