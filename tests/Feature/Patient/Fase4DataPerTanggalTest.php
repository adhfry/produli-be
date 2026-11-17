<?php

namespace Tests\Feature\Patient;

use App\Models\Kabupaten;
use App\Models\PatientDailySnapshot;
use App\Models\PatientsCache;
use App\Models\Puskesmas;
use App\Models\RiskClassification;
use App\Models\User;
use App\Services\Patient\PatientSnapshotService;
use App\Services\Risk\RiskClassificationHistoryService;
use Carbon\Carbon;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 4 blueprint docs/planning/16 (2026-11-17): kondisi pasien per tanggal (berdasar tanggal
 * pemeriksaan), snapshot harian, bandingkan dengan tanggal, filter membaik.
 */
class Fase4DataPerTanggalTest extends TestCase
{
    use RefreshDatabase;

    private Puskesmas $pkm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $kab = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $this->pkm = Puskesmas::create(['kabupaten_id' => $kab->id, 'kode_internal' => 'PKM-G', 'nama' => 'Puskesmas Gapura']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pasien(int $ext, string $nama): PatientsCache
    {
        return PatientsCache::create(['external_patient_id' => $ext, 'nik_hash' => "H-{$ext}", 'nama' => $nama,
            'wilayah_status' => 'unknown', 'puskesmas_id' => $this->pkm->id, 'is_prolanis' => true]);
    }

    private function klasifikasi(PatientsCache $p, string $level, string $periksa, string $dihitung, bool $terbaru): RiskClassification
    {
        return RiskClassification::create(['patient_id' => $p->id, 'level' => $level, 'criteria_snapshot' => [],
            'assessment_date' => $periksa, 'computed_at' => $dihitung, 'is_latest' => $terbaru]);
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('super_admin');
        Sanctum::actingAs($u);

        return $u;
    }

    public function test_kondisi_per_tanggal_memakai_tanggal_pemeriksaan_bukan_tanggal_hitung(): void
    {
        $p = $this->pasien(1, 'Siti');
        // Semua dihitung massal 17 Agustus, padahal pemeriksaannya Mei dan Oktober.
        $this->klasifikasi($p, 'sedang', '2026-05-08', '2026-08-12 10:00:00', false);
        $this->klasifikasi($p, 'berat', '2026-05-08', '2026-08-17 15:00:00', false); // hitung ulang, pemeriksaan sama
        $this->klasifikasi($p, 'ringan', '2026-10-02', '2026-10-09 09:00:00', true);
        $svc = app(RiskClassificationHistoryService::class);

        $this->assertNull($svc->effectiveFor($p->id, Carbon::parse('2026-04-30')), 'belum ada pemeriksaan');
        $this->assertSame('berat', $svc->effectiveFor($p->id, Carbon::parse('2026-07-31'))?->level, 'Juli: pemeriksaan Mei, hitungan terbaru');
        $this->assertSame('ringan', $svc->effectiveFor($p->id, Carbon::parse('2026-10-31'))?->level);
    }

    public function test_snapshot_harian_mencatat_level_dan_puskesmas_dan_aman_diulang(): void
    {
        $a = $this->pasien(1, 'Siti');
        $b = $this->pasien(2, 'Budi'); // belum ada hasil lab
        $this->klasifikasi($a, 'sedang', '2026-11-01', '2026-11-02 02:00:00', true);

        $this->artisan('produli:snapshot-harian')->assertSuccessful();
        $this->artisan('produli:snapshot-harian')->assertSuccessful();

        $this->assertSame(2, PatientDailySnapshot::count());
        $sa = PatientDailySnapshot::where('patients_cache_id', $a->id)->sole();
        $this->assertSame('sedang', $sa->risk_level);
        $this->assertSame($this->pkm->id, $sa->puskesmas_id);
        $this->assertNull(PatientDailySnapshot::where('patients_cache_id', $b->id)->value('risk_level'));
    }

    public function test_snapshot_yang_ada_dipakai_untuk_tanggalnya(): void
    {
        $p = $this->pasien(1, 'Siti');
        $lama = $this->klasifikasi($p, 'berat', '2026-11-01', '2026-11-01 02:00:00', false);
        $baru = $this->klasifikasi($p, 'ringan', '2026-11-01', '2026-11-05 02:00:00', true); // hitung ulang belakangan
        PatientDailySnapshot::create(['snapshot_date' => '2026-11-03', 'patients_cache_id' => $p->id, 'puskesmas_id' => $this->pkm->id,
            'risk_level' => 'berat', 'risk_classification_id' => $lama->id]);

        $svc = app(PatientSnapshotService::class);
        $this->assertSame('berat', $svc->klasifikasiPada([$p->id], Carbon::parse('2026-11-03'))->get($p->id)?->level, 'tercatat apa adanya pada hari itu');
        $this->assertSame('ringan', $svc->klasifikasiPada([$p->id], Carbon::parse('2026-11-04'))->get($p->id)?->level, 'tanpa snapshot: rekonstruksi');
    }

    public function test_daftar_pasien_per_tanggal_bandingkan_dan_filter_membaik(): void
    {
        Carbon::setTestNow('2026-11-17 09:00:00');
        $membaik = $this->pasien(1, 'Membaik');
        $this->klasifikasi($membaik, 'berat', '2026-06-01', '2026-08-17 10:00:00', false);
        $this->klasifikasi($membaik, 'ringan', '2026-10-01', '2026-10-09 10:00:00', true);
        $memburuk = $this->pasien(2, 'Memburuk');
        $this->klasifikasi($memburuk, 'ringan', '2026-06-01', '2026-08-17 10:00:00', false);
        $this->klasifikasi($memburuk, 'sedang', '2026-10-01', '2026-10-09 10:00:00', true);
        $tetap = $this->pasien(3, 'Tetap');
        $this->klasifikasi($tetap, 'sedang', '2026-06-01', '2026-08-17 10:00:00', true);
        $baru = $this->pasien(4, 'Belum Ada Lab');
        $this->admin();

        $items = collect($this->getJson('/api/v1/patients?tanggal=2026-07-31&per_page=50')->assertOk()->json('data.items'))->keyBy('nama');
        $this->assertSame('berat', $items['Membaik']['period_risk_level']);
        $this->assertSame('2026-06-01', $items['Membaik']['period_assessment_date']);
        $this->assertSame('ringan', $items['Membaik']['risk_level'], 'status terkini tetap ada');

        $items = collect($this->getJson('/api/v1/patients?bandingkan=2026-07-31&per_page=50')->assertOk()->json('data.items'))->keyBy('nama');
        $this->assertSame('membaik', $items['Membaik']['perubahan_risiko']);
        $this->assertSame('memburuk', $items['Memburuk']['perubahan_risiko']);
        $this->assertSame('tetap', $items['Tetap']['perubahan_risiko']);
        $this->assertNull($items['Belum Ada Lab']['perubahan_risiko']);

        $res = $this->getJson('/api/v1/patients?bandingkan=2026-07-31&perubahan=membaik&per_page=50')->assertOk();
        $this->assertSame(['Membaik'], collect($res->json('data.items'))->pluck('nama')->all());
        $this->assertSame(1, $res->json('data.pagination.total'));
    }

    public function test_validasi_tanggal(): void
    {
        $this->admin();
        $this->getJson('/api/v1/patients?perubahan=membaik')->assertStatus(422);
        $this->getJson('/api/v1/patients?tanggal='.now()->addDay()->toDateString())->assertStatus(422);
    }
}
