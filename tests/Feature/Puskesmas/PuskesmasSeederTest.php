<?php

namespace Tests\Feature\Puskesmas;

use App\Models\Kabupaten;
use App\Models\Kecamatan;
use App\Models\Puskesmas;
use Database\Seeders\PuskesmasSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Regresi untuk PuskesmasSeeder (docs/planning/02 §15) -- 1 puskesmas per kecamatan (nama
 * sama), KECUALI 4 kecamatan pengecualian (2 puskesmas, nama beda dari kecamatan). Dites
 * dengan subset kecamatan kecil (bukan 27 asli) supaya cepat, tapi tetap mencakup salah satu
 * kecamatan pengecualian ASLI supaya jalur itu ikut teruji.
 */
class PuskesmasSeederTest extends TestCase
{
    use RefreshDatabase;

    private function makeKecamatan(string $nama, int $kabupatenId): Kecamatan
    {
        return Kecamatan::create([
            'kabupaten_id' => $kabupatenId,
            'kode_kemendagri' => 'K-'.$nama,
            'nama' => $nama,
        ]);
    }

    public function test_seeder_menghasilkan_puskesmas_sesuai_jumlah_kecamatan_dan_pengecualian(): void
    {
        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);

        $this->makeKecamatan('Ambunten', $kabupaten->id);
        $this->makeKecamatan('Gapura', $kabupaten->id);
        $this->makeKecamatan('Kota Sumenep', $kabupaten->id); // pengecualian -> Pandian + Pamolokan
        $this->makeKecamatan('Batang Batang', $kabupaten->id); // pengecualian -> Batang-Batang + Legung

        (new PuskesmasSeeder)->run();

        // 2 kecamatan biasa (1 puskesmas) + 2 kecamatan pengecualian (2 puskesmas) = 2 + 4 = 6.
        $this->assertSame(6, Puskesmas::count());

        $nama = Puskesmas::pluck('nama')->sort()->values()->all();
        $this->assertEqualsCanonicalizing([
            'Puskesmas Ambunten',
            'Puskesmas Gapura',
            'Puskesmas Pandian',
            'Puskesmas Pamolokan',
            'Puskesmas Batang-Batang',
            'Puskesmas Legung',
        ], $nama);

        // Nama kecamatan APA ADANYA (tanpa pemecahan) tidak boleh ikut jadi puskesmas sendiri.
        $this->assertFalse(Puskesmas::where('nama', 'Puskesmas Kota Sumenep')->exists());
        $this->assertFalse(Puskesmas::where('nama', 'Puskesmas Batang Batang')->exists());
    }

    public function test_kolom_kontak_baru_kosong_saat_seed(): void
    {
        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $this->makeKecamatan('Ambunten', $kabupaten->id);

        (new PuskesmasSeeder)->run();

        $puskesmas = Puskesmas::first();
        $this->assertNull($puskesmas->no_telp);
        $this->assertNull($puskesmas->no_wa);
        $this->assertNull($puskesmas->latitude);
        $this->assertNull($puskesmas->longitude);
        $this->assertNull($puskesmas->deskripsi);
        $this->assertTrue($puskesmas->status_aktif);
    }

    public function test_seeder_idempotent_tidak_duplikat_saat_dijalankan_ulang(): void
    {
        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $this->makeKecamatan('Ambunten', $kabupaten->id);
        $this->makeKecamatan('Kota Sumenep', $kabupaten->id);

        (new PuskesmasSeeder)->run();
        (new PuskesmasSeeder)->run();

        $this->assertSame(3, Puskesmas::count()); // Ambunten + Pandian + Pamolokan, bukan 6.
    }

    public function test_seeder_gagal_jelas_kalau_kecamatan_kosong(): void
    {
        $this->expectException(RuntimeException::class);

        (new PuskesmasSeeder)->run();
    }
}
