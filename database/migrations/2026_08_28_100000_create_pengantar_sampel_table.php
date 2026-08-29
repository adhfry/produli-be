<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profil "Pengantar Sampel" -- role baru untuk modul Kirim Data Prolanis ke Labkesda Sumenep.
 * Berbeda dari kader/tenaga_kesehatan (yang urusannya pemeriksaan/pendampingan pasien), peran
 * ini murni identitas kurir yang ditugaskan admin_puskesmas/pj_prolanis untuk mengantar sampel
 * fisik+data pasien Prolanis ke Labkesda. Struktur SENGAJA dipangkas dari tabel `tenaga_kesehatan`
 * (lihat 2026_08_11_160000_create_tenaga_kesehatan_table.php) -- tidak perlu pj_id/alamat/gender/
 * tgl_lahir karena kurir tidak pernah jadi subjek pemeriksaan atau butuh supervisi PJ perorangan,
 * cukup identitas kontak untuk dihubungi/dilacak saat mengantar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengantar_sampel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('puskesmas_id')->constrained('puskesmas')->restrictOnDelete();
            $table->boolean('status_aktif')->default(true);
            $table->string('no_hp', 20);
            $table->string('no_wa', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengantar_sampel');
    }
};
