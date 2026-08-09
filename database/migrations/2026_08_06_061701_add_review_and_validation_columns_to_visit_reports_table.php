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
            // Tahap 1 (docs/planning/02 §11): PJ Prolanis menerima laporan dari kadernya sendiri
            // -- pengakuan operasional bahwa laporan sudah masuk & diketahui, BUKAN keputusan
            // sah/tidak sah (itu tahap 2 di bawah).
            $table->foreignId('pj_reviewed_by')->nullable()->after('sync_error')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('pj_reviewed_at')->nullable()->after('pj_reviewed_by');

            // Tahap 2: validasi final oleh super_admin -- SATU-SATUNYA penentu keabsahan laporan,
            // bisa kapan pun, tidak wajib menunggu tahap 1 (super_admin punya kewenangan tertinggi).
            $table->enum('validation_status', ['pending', 'valid', 'invalid'])
                ->default('pending')->after('pj_reviewed_at');
            $table->foreignId('validated_by')->nullable()->after('validation_status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable()->after('validated_by');
            $table->text('validation_note')->nullable()->after('validated_at');

            $table->index('validation_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visit_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pj_reviewed_by');
            $table->dropConstrainedForeignId('validated_by');
            $table->dropColumn(['pj_reviewed_at', 'validation_status', 'validated_at', 'validation_note']);
        });
    }
};
