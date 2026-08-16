<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 (docs plan "cozy-mapping-breeze") -- kader/nakes sebelumnya cuma bisa pilih SATU
 * tindakan per kunjungan (enum tunggal), padahal di lapangan bisa lebih dari satu sekaligus
 * (mis. diberi obat SEKALIGUS dirujuk). `tindakan` diubah jadi array (json), plus 2 kolom baru
 * untuk alur rujukan: `cara_rujukan` (diisi kalau 'dirujuk_puskesmas' ada di tindakan) dan
 * `rujukan_status` (dikonfirmasi/dibatalkan admin_puskesmas/pj_prolanis di halaman baru
 * /dashboard/rujukan, Fase 3).
 *
 * Backfill dilakukan PER-ROW di PHP (bukan raw SQL JSON_ARRAY) supaya portable across
 * MySQL (produksi) & SQLite (test, RefreshDatabase) -- MySQL 5.7 lama & SQLite tanpa ekstensi
 * JSON1 tidak selalu punya JSON_ARRAY() built-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_reports', function (Blueprint $table) {
            $table->json('tindakan_tmp')->nullable()->after('tindakan');
        });

        DB::table('visit_reports')->whereNotNull('tindakan')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('visit_reports')->where('id', $row->id)->update([
                    'tindakan_tmp' => json_encode([$row->tindakan]),
                ]);
            }
        });

        Schema::table('visit_reports', function (Blueprint $table) {
            $table->dropColumn('tindakan');
        });

        Schema::table('visit_reports', function (Blueprint $table) {
            $table->renameColumn('tindakan_tmp', 'tindakan');
        });

        Schema::table('visit_reports', function (Blueprint $table) {
            // Alur rujukan (docs plan Fase 2/3) -- diisi kader/nakes saat tindakan mencakup
            // 'dirujuk_puskesmas', dikonfirmasi/dibatalkan admin_puskesmas/pj_prolanis di
            // halaman /dashboard/rujukan (Fase 3, belum dibangun di migration ini).
            $table->enum('cara_rujukan', ['datang_sendiri', 'dijemput_ambulan', 'diantar_keluarga', 'diantar_nakes_kader'])
                ->nullable()->after('tindakan');
            $table->enum('rujukan_status', ['menunggu_konfirmasi', 'dikonfirmasi', 'dibatalkan'])
                ->nullable()->after('cara_rujukan');
        });
    }

    public function down(): void
    {
        Schema::table('visit_reports', function (Blueprint $table) {
            $table->string('tindakan_tmp', 30)->nullable()->after('tindakan');
        });

        // Ambil elemen PERTAMA array sebagai representasi terbaik saat rollback (satu-satunya
        // yang muat di enum tunggal lama) -- lossy kalau row-nya sempat py >1 tindakan, tapi
        // itu memang skenario yang TIDAK mungkin ada sebelum migration ini pernah dijalankan naik.
        DB::table('visit_reports')->whereNotNull('tindakan')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $decoded = json_decode((string) $row->tindakan, true);
                $first = is_array($decoded) ? ($decoded[0] ?? null) : null;
                DB::table('visit_reports')->where('id', $row->id)->update(['tindakan_tmp' => $first]);
            }
        });

        Schema::table('visit_reports', function (Blueprint $table) {
            $table->dropColumn(['tindakan', 'cara_rujukan', 'rujukan_status']);
        });

        Schema::table('visit_reports', function (Blueprint $table) {
            $table->renameColumn('tindakan_tmp', 'tindakan');
        });
    }
};
