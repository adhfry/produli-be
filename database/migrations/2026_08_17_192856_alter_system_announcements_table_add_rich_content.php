<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rombak Pengumuman Sistem (docs/planning/02 §13) jadi punya konten kaya + penargetan role +
 * tingkat urgensi -- sebelumnya cuma title/description/type(info|success|warning) polos lewat
 * modal inline di dashboard. `type` diganti `urgency` (info|penting|darurat, 3 tingkat sesuai
 * rekomendasi -- lihat AnnouncementService docblock) karena semantiknya beda: `type` lama
 * sekadar warna badge, `urgency` baru menentukan PERILAKU modal (darurat wajib diklik "Saya
 * Mengerti", tidak bisa ditutup lewat klik-luar/backdrop).
 *
 * Ganti kolom lewat ADD baru + backfill + DROP lama (bukan raw ALTER...CHANGE MySQL-only) supaya
 * migration ini portable ke SQLite juga (dipakai test suite, phpunit.xml DB_CONNECTION=sqlite) --
 * doctrine/dbal (composer.json) sudah terpasang jadi dropColumn() aman dipakai di kedua driver.
 *
 * Kolom baru SEMUA nullable -- halaman pembuat pengumuman baru (super_admin) menyediakan default
 * (icon 'LucideMegaphone', color mengikuti urgency) kalau operator tidak memilih eksplisit.
 * `description` LAMA (text, sudah ada) tetap dipertahankan sebagai body -- TIDAK di-drop, cuma
 * ditambah kolom baru di sekitarnya (mengurangi risiko migrasi data, deskripsi lama tetap utuh).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_announcements', function (Blueprint $table) {
            $table->enum('urgency', ['info', 'penting', 'darurat'])->default('info')->after('type');
        });

        // Baris lama 'success'/'warning' (skema lama) dipetakan ke tingkat urgensi baru yang
        // paling dekat maknanya -- 'success' (positif) -> 'info', 'warning' (perlu perhatian)
        // -> 'penting'. Tidak ada baris lama yang cocok jadi 'darurat' (tier itu memang belum
        // pernah ada), operator bisa naikkan manual lewat halaman baru kalau perlu.
        DB::table('system_announcements')->where('type', 'success')->update(['urgency' => 'info']);
        DB::table('system_announcements')->where('type', 'warning')->update(['urgency' => 'penting']);
        DB::table('system_announcements')->where('type', 'info')->update(['urgency' => 'info']);

        Schema::table('system_announcements', function (Blueprint $table) {
            $table->dropColumn('type');

            // Ikon notif (nama komponen Lucide, mis. 'LucideMegaphone') -- ditampilkan di modal
            // inbox & daftar. Nullable, halaman pembuat isi default per urgency kalau kosong.
            $table->string('icon', 60)->nullable()->after('urgency');
            // Kunci warna tema (mis. 'primary'/'warning'/'danger'/'success'/'info') -- BUKAN hex
            // bebas, supaya konsisten dengan token desain Tailwind yang sudah dipakai di seluruh
            // frontend, operator pilih dari daftar terbatas (bukan color picker bebas).
            $table->string('color', 30)->nullable()->after('icon');
            $table->string('image_url', 500)->nullable()->after('color');
            $table->string('button_label', 60)->nullable()->after('image_url');
            $table->string('button_url', 500)->nullable()->after('button_label');
            // null/[] = SEMUA role (perilaku lama, default supaya pengumuman existing tetap
            // tampil ke semua orang setelah migrasi). Array berisi subset dari Role enum frontend
            // (super_admin/admin_puskesmas/pj_prolanis/kader/tenaga_kesehatan) -- divalidasi di
            // CreateAnnouncementRequest, BUKAN foreign key (roles Spatie bukan tabel yang cocok
            // di-reference langsung dengan nama sesimpel ini).
            $table->json('target_roles')->nullable()->after('button_url');
        });
    }

    public function down(): void
    {
        Schema::table('system_announcements', function (Blueprint $table) {
            $table->dropColumn(['icon', 'color', 'image_url', 'button_label', 'button_url', 'target_roles']);
            $table->enum('type', ['info', 'success', 'warning'])->default('info')->after('description');
        });

        DB::table('system_announcements')->where('urgency', 'penting')->update(['type' => 'warning']);
        DB::table('system_announcements')->where('urgency', 'darurat')->update(['type' => 'warning']);
        DB::table('system_announcements')->where('urgency', 'info')->update(['type' => 'info']);

        Schema::table('system_announcements', function (Blueprint $table) {
            $table->dropColumn('urgency');
        });
    }
};
