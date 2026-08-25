<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permintaan user: begitu kader/nakes kunjungan & mengonfirmasi lokasi (GPS sungguhan di rumah
 * pasien, VisitReportService::submit() confirmedPatientLocation=true), desa/kecamatan pasien
 * SEHARUSNYA otomatis ter-resolve dari titik koordinat itu -- bukan cuma menyimpan lat/long
 * mentah seperti sekarang. Text-matching WilayahResolver::resolve() TIDAK BISA menolong sini
 * (~93% pasien produksi wilayah_status='unknown' -- SiLAKES memang tidak pernah kirim kel_desa/
 * kecamatan sama sekali utk mereka, bukan soal matching yang kurang pintar). Satu-satunya sumber
 * kebenaran independen yang tersedia adalah titik GPS kader sendiri.
 *
 * `boundary` = geometri Polygon (array koordinat [lng,lat] GeoJSON) per desa, diimpor dari
 * produli-frontend/public/sumenep_desa.geojson (334 fitur, SUDAH dipakai peta frontend
 * sekarang -- lihat produli:import-desa-boundaries) supaya bisa dites "titik GPS ini masuk desa
 * mana" (ray-casting) SEPENUHNYA di backend, tanpa panggilan API eksternal. Nullable -- desa yang
 * belum diimpor boundary-nya (mis. sebelum command dijalankan) tetap resolve via text-matching
 * seperti biasa, cuma resolveByCoordinates() tidak bisa menjangkau mereka.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('desa', function (Blueprint $table) {
            $table->json('boundary')->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('desa', function (Blueprint $table) {
            $table->dropColumn('boundary');
        });
    }
};
