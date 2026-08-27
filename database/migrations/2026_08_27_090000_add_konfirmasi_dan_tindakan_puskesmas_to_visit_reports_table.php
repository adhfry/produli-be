<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permintaan user, halaman /dashboard/rujukan -- SEBELUM ini, "dikonfirmasi kedatangannya jam
 * berapa oleh siapa" TIDAK PERNAH tercatat sama sekali (RujukanService::konfirmasi() cuma
 * update rujukan_status, tidak ada jejak waktu/pelaku). Ditambah juga tindak lanjut puskesmas
 * SETELAH pasien dikonfirmasi datang (rawat inap/edukasi/obat tambahan/dst) -- sebelumnya tidak
 * ada tempat mencatat ini sama sekali, admin cuma tahu pasien "sudah dikonfirmasi", bukan
 * ditangani seperti apa.
 *
 * confirmed_at/confirmed_by mencatat SIAPA & KAPAN keputusan konfirmasi/pembatalan diambil
 * (bukan cuma status akhirnya) -- diisi utk KEDUA hasil (dikonfirmasi maupun dibatalkan), sama
 * prinsipnya dengan validated_at/validated_by yang sudah ada utk alur validasi laporan.
 *
 * tindakan_puskesmas array (json, pola sama persis `tindakan` yang sudah ada -- checkbox
 * multi-pilih, bukan enum tunggal, krn di lapangan bisa lebih dari satu sekaligus mis. edukasi
 * SEKALIGUS diberi obat tambahan) + catatan bebas (hasil diagnosa) + jejak siapa/kapan
 * mengisinya -- HANYA relevan setelah rujukan_status='dikonfirmasi' (lihat guard di
 * RujukanService::inputTindakanLanjutan()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_reports', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('rujukan_status');
            $table->foreignId('confirmed_by')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();

            $table->json('tindakan_puskesmas')->nullable()->after('confirmed_by');
            $table->text('catatan_tindakan_puskesmas')->nullable()->after('tindakan_puskesmas');
            $table->timestamp('tindakan_puskesmas_at')->nullable()->after('catatan_tindakan_puskesmas');
            $table->foreignId('tindakan_puskesmas_by')->nullable()->after('tindakan_puskesmas_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visit_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropConstrainedForeignId('tindakan_puskesmas_by');
            $table->dropColumn(['confirmed_at', 'tindakan_puskesmas', 'catatan_tindakan_puskesmas', 'tindakan_puskesmas_at']);
        });
    }
};
