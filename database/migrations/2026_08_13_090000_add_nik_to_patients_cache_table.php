<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NIK asli (bukan nik_hash) -- permintaan Kepala Dinas untuk laporan resmi. SiLAKES kini
 * mengirim field `nik` plaintext di response sync (lihat docs/planning/04 di repo SiLAKES),
 * disimpan berdampingan dengan nik_hash yang tetap dipakai apa adanya untuk jalur pencarian
 * hash-vs-hash yang sudah ada. Sengaja TIDAK disertakan di PatientResource (API JSON dashboard)
 * -- cuma dikonsumsi server-side di jalur PDF export, sama seperti nik_hash sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients_cache', function (Blueprint $table) {
            $table->string('nik', 16)->nullable()->after('nik_hash');
        });
    }

    public function down(): void
    {
        Schema::table('patients_cache', function (Blueprint $table) {
            $table->dropColumn('nik');
        });
    }
};
