<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * puskesmas SEBELUMNYA tidak punya relasi kecamatan sama sekali -- filter kecamatan
     * "otomatis terkunci ke wilayah sendiri" utk admin_puskesmas/pj_prolanis (dashboard/pasien,
     * modal Buat Penugasan) butuh ini. 26/31 nama puskesmas cocok langsung dengan nama kecamatan
     * (mis. Puskesmas Gapura -> Kec. Gapura); 5 SISANYA (Legung, Moncek Tengah, Pagerungan
     * Besar, Pamolokan, Pandian) DIKONFIRMASI MANUAL oleh product owner (bukan tebakan/name-
     * matching) karena namanya tidak cocok dengan nama kecamatan mana pun.
     */
    public function up(): void
    {
        Schema::table('puskesmas', function (Blueprint $table) {
            $table->foreignId('kecamatan_id')->nullable()->after('kabupaten_id')
                ->constrained('kecamatan')->nullOnDelete();
        });

        // Nama puskesmas (tanpa prefix "Puskesmas ") -> kecamatan.nama PERSIS SESUAI ISI TABEL
        // kecamatan (BUKAN penulisan di public/sumenep.geojson frontend -- keduanya beda ejaan
        // utk beberapa nama, mis. "Batang Batang" (spasi, tabel ini) vs "Batang-Batang" (strip,
        // geojson), "Ra'as" (apostrof, tabel ini) vs "Raas" (geojson) -- match harus ke tabel
        // kecamatan karena itu yang di-foreign-key di sini, bukan file statis frontend).
        $map = [
            'Ambunten' => 'Ambunten',
            'Arjasa' => 'Arjasa',
            'Batang-Batang' => 'Batang Batang',
            'Batuan' => 'Batuan',
            'Batuputih' => 'Batuputih',
            'Bluto' => 'Bluto',
            'Dasuk' => 'Dasuk',
            'Dungkek' => 'Dungkek',
            'Ganding' => 'Ganding',
            'Gapura' => 'Gapura',
            'Gayam' => 'Gayam',
            'Giliginting' => 'Giliginting',
            'Guluk-Guluk' => 'Guluk-Guluk',
            'Kalianget' => 'Kalianget',
            'Kangayan' => 'Kangayan',
            'Legung' => 'Batang Batang',
            'Lenteng' => 'Lenteng',
            'Manding' => 'Manding',
            'Masalembu' => 'Masalembu',
            'Moncek Tengah' => 'Lenteng',
            'Nonggunong' => 'Nonggunong',
            'Pagerungan Besar' => 'Sapeken',
            'Pamolokan' => 'Kota Sumenep',
            'Pandian' => 'Kota Sumenep',
            'Pasongsongan' => 'Pasongsongan',
            'Pragaan' => 'Pragaan',
            "Ra'as" => "Ra'as",
            'Rubaru' => 'Rubaru',
            'Sapeken' => 'Sapeken',
            'Saronggi' => 'Saronggi',
            'Talango' => 'Talango',
        ];

        $kecamatanIdByName = DB::table('kecamatan')->pluck('id', 'nama');

        foreach ($map as $puskesmasShortName => $kecamatanName) {
            $kecamatanId = $kecamatanIdByName[$kecamatanName] ?? null;

            if ($kecamatanId === null) {
                continue; // Data kecamatan belum ada (mis. seed parsial) -- biarkan null, bukan gagalkan migration.
            }

            DB::table('puskesmas')
                ->where('nama', 'Puskesmas '.$puskesmasShortName)
                ->update(['kecamatan_id' => $kecamatanId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('puskesmas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kecamatan_id');
        });
    }
};
