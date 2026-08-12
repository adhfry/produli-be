<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * visit_assignments.kader_id awalnya NOT NULL (satu-satunya jenis petugas dulu = kader).
 * Sekarang satu baris visit_assignments merepresentasikan SATU kunjungan oleh SALAH SATU
 * jenis petugas -- kader_id ATAU tenaga_kesehatan_id terisi (persis pola
 * care_assignments.worker_type + FK ganda nullable), tidak pernah dua-duanya. Assignment kader
 * satu-kali yang SUDAH ADA (VisitAssignmentService, tidak diubah) tetap selalu isi kader_id,
 * tenaga_kesehatan_id NULL -- tidak ada perubahan perilaku untuk alur lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_assignments', function (Blueprint $table) {
            $table->foreignId('kader_id')->nullable()->change();
            $table->foreignId('tenaga_kesehatan_id')->nullable()->after('kader_id')
                ->constrained('tenaga_kesehatan')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visit_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenaga_kesehatan_id');
            $table->foreignId('kader_id')->nullable(false)->change();
        });
    }
};
