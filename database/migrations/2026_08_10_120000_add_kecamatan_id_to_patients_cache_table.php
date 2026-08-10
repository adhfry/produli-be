<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bug ditemukan lewat audit: WilayahResolver::resolve() SUDAH berhasil mencocokkan kecamatan
 * dari teks bebas (mis. "KOTA SUMENEP" -> Kecamatan Kota Sumenep) walau desa-nya sendiri belum
 * diketahui (kel_desa_raw null/tidak match) -- tapi hasil match itu dulu TIDAK PERNAH disimpan
 * di mana pun (cuma dipakai sekilas di memori utk resolvePuskesmas() lalu dibuang), karena
 * desain awal (docs/planning/02 §2a) beranggapan kecamatan_id selalu bisa diturunkan lewat
 * desa_id -> desa.kecamatan_id. Itu betul KALAU desa_id ada -- tapi utk populasi besar pasien
 * yang kecamatan-nya jelas tapi desa-nya tidak (~19,6% dari total per temuan gap wilayah),
 * tidak ada desa_id sama sekali utk diturunkan lewat, jadi kecamatan yang sebetulnya SUDAH
 * diketahui ikut hilang -- membuat mereka tidak pernah muncul di agregat risikoPerKecamatan()
 * (DashboardService, INNER JOIN lewat desa) walau datanya ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients_cache', function (Blueprint $table) {
            $table->foreignId('kecamatan_id')->nullable()->after('desa_id')
                ->constrained('kecamatan')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('patients_cache', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kecamatan_id');
        });
    }
};
