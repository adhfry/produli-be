<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * care_assignment_id NULLABLE + default 'manual' pada visit_origin -- alur assign kader
 * satu-kali yang SUDAH ADA (VisitAssignmentService::assign()/assignBulk(), TIDAK diubah sama
 * sekali oleh revisi ini) terus menghasilkan baris care_assignment_id=NULL,
 * visit_origin='manual' persis seperti sebelumnya. Kolom ini cuma dipakai baris BARU yang
 * dibuat CareAssignmentService (cadence_generated/adhoc).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_assignments', function (Blueprint $table) {
            $table->foreignId('care_assignment_id')->nullable()->after('kader_id')
                ->constrained('care_assignments')->nullOnDelete();
            $table->enum('visit_origin', ['manual', 'cadence_generated', 'adhoc'])->default('manual')
                ->after('assignment_method');
        });
    }

    public function down(): void
    {
        Schema::table('visit_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('care_assignment_id');
            $table->dropColumn('visit_origin');
        });
    }
};
