<?php

namespace Tests\Feature\Console;

use App\Models\Desa;
use App\Models\Kabupaten;
use App\Models\Kecamatan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi untuk produli:import-desa-boundaries -- fixture (tests/Fixtures/
 * desa_boundaries_sample.geojson) diambil LANGSUNG dari 2 fitur pertama
 * produli-frontend/public/sumenep_desa.geojson (kode_desa 35.29.01.1015 & 35.29.01.1016),
 * memverifikasi command ini benar-benar cocok dgn struktur GeoJSON sungguhan yang dipakai peta
 * frontend, bukan format rekaan sendiri.
 */
class ImportDesaBoundariesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function fixturePath(): string
    {
        return base_path('tests/Fixtures/desa_boundaries_sample.geojson');
    }

    public function test_import_mengisi_boundary_desa_yang_kode_kemendagri_nya_cocok(): void
    {
        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $kecamatan = Kecamatan::create(['kabupaten_id' => $kabupaten->id, 'kode_kemendagri' => '35.29.01', 'nama' => 'Kota Sumenep']);
        $desa = Desa::create(['kecamatan_id' => $kecamatan->id, 'kode_kemendagri' => '35.29.01.1015', 'nama' => 'Kepanjin']);

        // Fixture punya 2 fitur, tapi cuma kode 35.29.01.1015 (desa di atas) yang ada di DB --
        // kode 35.29.01.1016 tidak match (ikut ke laporan "tidak match" tanpa menggagalkan proses).
        $this->artisan('produli:import-desa-boundaries', ['--path' => $this->fixturePath()])
            ->expectsOutputToContain('1 desa diperbarui')
            ->assertExitCode(0);

        $boundary = $desa->fresh()->boundary;
        $this->assertNotNull($boundary);
        $this->assertIsArray($boundary);
        $this->assertIsArray($boundary[0][0]); // array of polygons -> array of rings -> array titik
    }

    public function test_import_melaporkan_kode_desa_yang_tidak_match_tanpa_menggagalkan_proses(): void
    {
        // Sengaja TIDAK membuat baris Desa apa pun -- kedua kode di fixture pasti tidak match.
        $this->artisan('produli:import-desa-boundaries', ['--path' => $this->fixturePath()])
            ->expectsOutputToContain('0 desa diperbarui')
            ->assertExitCode(0);
    }

    public function test_import_gagal_rapi_kalau_path_file_tidak_ada(): void
    {
        $this->artisan('produli:import-desa-boundaries', ['--path' => '/tmp/tidak-ada-file-ini.geojson'])
            ->assertExitCode(1);
    }
}
