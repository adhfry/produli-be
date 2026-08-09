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
        Schema::table('puskesmas', function (Blueprint $table) {
            // 'alamat' TIDAK ditambahkan di sini -- sudah ada sejak migration awal
            // (create_puskesmas_table). Kolom di bawah ini murni kolom baru (docs/planning/02
            // §15, label frontend "Data Instansi"), SEMUA nullable -- dibiarkan NULL saat seed,
            // diisi belakangan lewat PATCH /api/v1/puskesmas/{id} oleh admin_puskesmas/super_admin.
            $table->string('no_telp', 20)->nullable()->after('alamat');
            $table->string('no_wa', 20)->nullable()->after('no_telp');
            $table->decimal('latitude', 10, 7)->nullable()->after('no_wa');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->text('deskripsi')->nullable()->after('longitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('puskesmas', function (Blueprint $table) {
            $table->dropColumn(['no_telp', 'no_wa', 'latitude', 'longitude', 'deskripsi']);
        });
    }
};
