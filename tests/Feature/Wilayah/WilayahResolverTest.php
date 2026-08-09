<?php

namespace Tests\Feature\Wilayah;

use App\Models\Desa;
use App\Models\Kabupaten;
use App\Models\Kecamatan;
use App\Models\Puskesmas;
use App\Models\WilayahMapping;
use App\Services\Wilayah\WilayahResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi untuk WilayahResolver — dulu diverifikasi lewat tinker ad-hoc ke kopipu_db asli
 * (lihat riwayat percakapan), sekarang dipermanenkan supaya bug yang sudah pernah ditemukan
 * (KEC. KOTA, junk kecamatan "-", typo Giliginting, encoding korup Kota Sumenep) tidak
 * diam-diam balik kalau resolver ini diubah lagi nanti.
 */
class WilayahResolverTest extends TestCase
{
    use RefreshDatabase;

    private WilayahResolver $resolver;

    private Kecamatan $kotaSumenep;

    private Kecamatan $talango;

    private Kecamatan $masalembu;

    private Kecamatan $giliginting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(WilayahResolver::class);

        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);

        $this->kotaSumenep = Kecamatan::create(['kabupaten_id' => $kabupaten->id, 'kode_kemendagri' => 'K17', 'nama' => 'Kota Sumenep']);
        Desa::create(['kecamatan_id' => $this->kotaSumenep->id, 'kode_kemendagri' => 'D-KOLOR', 'nama' => 'Kolor']);

        $this->talango = Kecamatan::create(['kabupaten_id' => $kabupaten->id, 'kode_kemendagri' => 'K28', 'nama' => 'Talango']);
        $desaTalango = Desa::create(['kecamatan_id' => $this->talango->id, 'kode_kemendagri' => 'D-TALANGO', 'nama' => 'Talango']);
        $pkmTalango = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-TALANGO', 'nama' => 'Puskesmas Talango']);
        $desaTalango->update(['puskesmas_id' => $pkmTalango->id]);

        $this->masalembu = Kecamatan::create(['kabupaten_id' => $kabupaten->id, 'kode_kemendagri' => 'K20', 'nama' => 'Masalembu']);
        $pkmA = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-MSL-A', 'nama' => 'Puskesmas Masalembu A']);
        $pkmB = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-MSL-B', 'nama' => 'Puskesmas Masalembu B']);
        Desa::create(['kecamatan_id' => $this->masalembu->id, 'kode_kemendagri' => 'D-MSL-1', 'nama' => 'Pulau Satu', 'puskesmas_id' => $pkmA->id]);
        Desa::create(['kecamatan_id' => $this->masalembu->id, 'kode_kemendagri' => 'D-MSL-2', 'nama' => 'Pulau Dua', 'puskesmas_id' => $pkmB->id]);

        $this->giliginting = Kecamatan::create(['kabupaten_id' => $kabupaten->id, 'kode_kemendagri' => 'K13', 'nama' => 'Giliginting']);
        Desa::create(['kecamatan_id' => $this->giliginting->id, 'kode_kemendagri' => 'D-AENGANYAR', 'nama' => 'Aenganyar']);
    }

    public function test_resolved_dengan_puskesmas_via_desa(): void
    {
        $result = $this->resolver->resolve('Talango', 'Talango');
        $puskesmas = $this->resolver->resolvePuskesmas($result->desaId, $result->kecamatanId);

        $this->assertSame('resolved', $result->wilayahStatus);
        $this->assertSame('desa', $puskesmas['method']);
    }

    public function test_resolved_tapi_puskesmas_belum_di_assign_unresolvable(): void
    {
        $result = $this->resolver->resolve('Kolor', 'Kota Sumenep');
        $puskesmas = $this->resolver->resolvePuskesmas($result->desaId, $result->kecamatanId);

        $this->assertSame('resolved', $result->wilayahStatus);
        $this->assertSame('unresolvable', $puskesmas['method']);
    }

    public function test_encoding_fixup_kota_sum_tanda_tanya(): void
    {
        $result = $this->resolver->resolve('Kolor', 'KOTA SUM????');

        $this->assertSame($this->kotaSumenep->id, $result->kecamatanId);
        $this->assertSame('resolved', $result->wilayahStatus);
    }

    public function test_encoding_fixup_huruf_yunani_mirip_latin(): void
    {
        $result = $this->resolver->resolve('Kolor', 'KOTA SUMΕΝΕΡ');

        $this->assertSame($this->kotaSumenep->id, $result->kecamatanId);
    }

    public function test_alias_kec_kota_resolve_ke_kota_sumenep(): void
    {
        // Regresi: sebelum alias ini ditambahkan, "KEC. KOTA" jadi string kosong setelah
        // di-strip prefix administratif dan salah jatuh ke out_of_scope.
        $result = $this->resolver->resolve('Kolor', 'KEC. KOTA');

        $this->assertSame($this->kotaSumenep->id, $result->kecamatanId);
        $this->assertSame('resolved', $result->wilayahStatus);
    }

    public function test_alias_giligenting_typo_resolve_ke_giliginting(): void
    {
        // Regresi: "Giligenting"/"Giligenteng" adalah typo umum (47 pasien di data nyata)
        // untuk kecamatan "Giliginting".
        $result = $this->resolver->resolve('Aenganyar', 'Giligenting');

        $this->assertSame($this->giliginting->id, $result->kecamatanId);
        $this->assertSame('resolved', $result->wilayahStatus);
    }

    public function test_kecamatan_fallback_saat_desa_tidak_match_tapi_kecamatan_cuma_1_puskesmas(): void
    {
        $result = $this->resolver->resolve('Desa Fiktif Tidak Ada', 'Talango');
        $puskesmas = $this->resolver->resolvePuskesmas($result->desaId, $result->kecamatanId);

        $this->assertSame('unresolved', $result->wilayahStatus);
        $this->assertSame('kecamatan_fallback', $puskesmas['method']);
    }

    public function test_unresolvable_saat_kecamatan_punya_lebih_dari_1_puskesmas(): void
    {
        $result = $this->resolver->resolve('Desa Fiktif Tidak Ada', 'Masalembu');
        $puskesmas = $this->resolver->resolvePuskesmas($result->desaId, $result->kecamatanId);

        $this->assertSame('unresolved', $result->wilayahStatus);
        $this->assertSame('unresolvable', $puskesmas['method']);
    }

    public function test_unknown_saat_kel_desa_kosong_tapi_puskesmas_fallback_tetap_jalan(): void
    {
        $result = $this->resolver->resolve(null, 'Talango');
        $puskesmas = $this->resolver->resolvePuskesmas($result->desaId, $result->kecamatanId);

        $this->assertSame('unknown', $result->wilayahStatus);
        $this->assertSame('kecamatan_fallback', $puskesmas['method']);
    }

    public function test_junk_kel_desa_seperti_000_jadi_unresolved_bukan_unknown(): void
    {
        $result = $this->resolver->resolve('000', 'Talango');

        $this->assertSame('unresolved', $result->wilayahStatus);
    }

    public function test_junk_kecamatan_strip_diperlakukan_tidak_ada_info_bukan_out_of_scope(): void
    {
        $result = $this->resolver->resolve('Sesuatu', '-');

        $this->assertNotSame('out_of_scope', $result->wilayahStatus);
        $this->assertNull($result->kecamatanId);
    }

    public function test_out_of_scope_untuk_kecamatan_luar_sumenep(): void
    {
        $result = $this->resolver->resolve('Desa Apapun', 'Surabaya');

        $this->assertSame('out_of_scope', $result->wilayahStatus);
        $this->assertDatabaseMissing('wilayah_mapping', ['kecamatan_raw' => 'Surabaya']);
    }

    public function test_cache_wilayah_mapping_idempotent_tidak_duplikat(): void
    {
        $this->resolver->resolve('Talango', 'Talango');
        $this->resolver->resolve('Talango', 'Talango');

        $this->assertSame(1, WilayahMapping::where('kel_desa_raw', 'Talango')->where('kecamatan_raw', 'Talango')->count());
    }
}
