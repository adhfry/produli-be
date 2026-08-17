<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staf (admin_puskesmas/pj_prolanis/super_admin) tidak punya tabel domain sendiri seperti
 * kader/tenaga_kesehatan (yang masing-masing sudah punya status_aktif di tabelnya sendiri) --
 * kolom ini menyamakan pola nonaktifkan/aktifkan yang sama utk staf, supaya StaffService::delete()
 * tidak lagi jadi satu-satunya jalan "melepas" staf yang riwayat assigned_by-nya harus tetap ada.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('status_aktif')->default(true)->after('puskesmas_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('status_aktif');
        });
    }
};
