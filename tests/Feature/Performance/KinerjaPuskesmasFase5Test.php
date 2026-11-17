<?php

namespace Tests\Feature\Performance;

use App\Models\Kabupaten;
use App\Models\PatientsCache;
use App\Models\Puskesmas;
use App\Models\RiskClassification;
use App\Models\RiskTransitionScore;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 5 blueprint docs/planning/16 (2026-11-17): hitung ulang poin lama dengan aturan v2,
 * peringkat dengan rincian poin, daftar pasien membaik (hak akses), kartu ringkasan.
 */
class KinerjaPuskesmasFase5Test extends TestCase
{
    use RefreshDatabase;

    private Puskesmas $pkmA;

    private Puskesmas $pkmB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $kab = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $this->pkmA = Puskesmas::create(['kabupaten_id' => $kab->id, 'kode_internal' => 'PKM-A', 'nama' => 'Puskesmas A']);
        $this->pkmB = Puskesmas::create(['kabupaten_id' => $kab->id, 'kode_internal' => 'PKM-B', 'nama' => 'Puskesmas B']);
    }

    private function pasien(int $ext, Puskesmas $pkm): PatientsCache
    {
        return PatientsCache::create(['external_patient_id' => $ext, 'nik_hash' => "H-{$ext}", 'nama' => "Pasien {$ext}",
            'wilayah_status' => 'unknown', 'puskesmas_id' => $pkm->id]);
    }

    /** Baris skor LAMA (aturan v1) seperti yang ada di production, untuk diuji hitung ulangnya. */
    private function skorLama(PatientsCache $p, string $dari, string $ke, string $periksaDulu, string $periksaKini, int $poinLama): RiskTransitionScore
    {
        $prev = RiskClassification::create(['patient_id' => $p->id, 'level' => $dari, 'criteria_snapshot' => [], 'computed_at' => '2026-08-12 10:00:00', 'assessment_date' => $periksaDulu, 'is_latest' => false]);
        $curr = RiskClassification::create(['patient_id' => $p->id, 'level' => $ke, 'criteria_snapshot' => [], 'computed_at' => '2026-08-17 10:00:00', 'assessment_date' => $periksaKini, 'is_latest' => true]);

        return RiskTransitionScore::create([
            'patient_id' => $p->id, 'puskesmas_id' => $p->puskesmas_id, 'previous_risk_classification_id' => $prev->id,
            'current_risk_classification_id' => $curr->id, 'previous_risk_level' => $dari, 'current_risk_level' => $ke,
            'risk_delta' => 0, 'base_point' => $poinLama, 'final_point' => $poinLama, 'eligible' => false, 'calculated_at' => '2026-08-27 10:00:00',
        ]);
    }

    private function login(string $role, ?Puskesmas $pkm = null): User
    {
        $u = User::factory()->create(['puskesmas_id' => $pkm?->id]);
        $u->assignRole($role);
        Sanctum::actingAs($u);

        return $u;
    }

    public function test_hitung_ulang_uji_tidak_menulis_lalu_nyata_menerapkan_aturan_v2(): void
    {
        $a = $this->pasien(1, $this->pkmA);
        $labBaru = $this->skorLama($a, 'berat', 'sedang', '2026-05-01', '2026-10-01', 10);
        $b = $this->pasien(2, $this->pkmA);
        $hitungUlang = $this->skorLama($b, 'berat', 'tidak_berisiko', '2026-05-08', '2026-05-08', 30); // pemeriksaan sama

        $this->artisan('produli:hitung-ulang-poin --uji')->assertSuccessful()->expectsOutputToContain('"baris_berubah":2');
        $this->assertFalse($labBaru->fresh()->eligible, 'mode uji tidak menulis');

        $this->artisan('produli:hitung-ulang-poin')->assertSuccessful();

        $labBaru->refresh();
        $this->assertTrue($labBaru->eligible);
        $this->assertTrue($labBaru->lab_driven);
        $this->assertSame(10, $labBaru->final_point);
        $this->assertSame('2026-10-01', $labBaru->assessment_date->toDateString());
        $this->assertSame(2, $labBaru->rule_version);

        $hitungUlang->refresh();
        $this->assertFalse($hitungUlang->eligible, 'hitung ulang atas pemeriksaan yang sama bukan perbaikan');
        $this->assertSame(2, RiskTransitionScore::count(), 'tidak ada baris dihapus/ditambah');
    }

    public function test_peringkat_dan_rincian_poin(): void
    {
        $a = $this->pasien(1, $this->pkmA);
        $this->skorLama($a, 'berat', 'ringan', '2026-05-01', '2026-10-01', 20);
        $this->artisan('produli:hitung-ulang-poin')->assertSuccessful();
        $this->login('admin_puskesmas', $this->pkmB);

        $res = $this->getJson('/api/v1/kinerja-puskesmas?date_from=2026-10-01&date_to=2026-10-31')->assertOk();
        $row = $res->json('data.peringkat.0');
        $this->assertSame($this->pkmA->id, $row['puskesmas_id'], 'peringkat se-kabupaten terlihat semua role');
        $this->assertSame(20, $row['poin_perbaikan']);
        $this->assertSame(20, $row['total_poin']);
        $this->assertSame(5, $res->json('data.aturan.bonus_kunjungan'));

        $this->getJson('/api/v1/kinerja-puskesmas?date_from=2026-09-01&date_to=2026-09-30')->assertOk()->assertJsonCount(0, 'data.peringkat');
    }

    public function test_daftar_pasien_membaik_hanya_untuk_puskesmas_sendiri_kecuali_super_admin(): void
    {
        $a = $this->pasien(1, $this->pkmA);
        $this->skorLama($a, 'sedang', 'tidak_berisiko', '2026-05-01', '2026-10-01', 20);
        $this->artisan('produli:hitung-ulang-poin')->assertSuccessful();

        $this->login('admin_puskesmas', $this->pkmB);
        $this->getJson("/api/v1/kinerja-puskesmas/{$this->pkmA->id}/pasien")->assertForbidden();

        $this->login('admin_puskesmas', $this->pkmA);
        $this->getJson("/api/v1/kinerja-puskesmas/{$this->pkmA->id}/pasien")->assertOk()
            ->assertJsonPath('data.items.0.nama', 'Pasien 1')
            ->assertJsonPath('data.items.0.dari_label', 'Sedang')
            ->assertJsonPath('data.items.0.ke_label', 'Terkendali');

        $this->login('super_admin');
        $this->getJson("/api/v1/kinerja-puskesmas/{$this->pkmA->id}/pasien")->assertOk()->assertJsonCount(1, 'data.items');

        $this->login('kader', $this->pkmA);
        $this->getJson('/api/v1/kinerja-puskesmas')->assertForbidden();
    }

    public function test_ringkasan_dashboard_membaik_dan_peringkat_bulan_ini(): void
    {
        $a = $this->pasien(1, $this->pkmA);
        $this->skorLama($a, 'berat', 'sedang', now()->subMonths(2)->toDateString(), now()->toDateString(), 10);
        $this->artisan('produli:hitung-ulang-poin')->assertSuccessful();

        $this->login('admin_puskesmas', $this->pkmA);
        $this->getJson('/api/v1/kinerja-puskesmas/ringkasan')->assertOk()
            ->assertJsonPath('data.membaik_1_hari', 1)
            ->assertJsonPath('data.membaik_30_hari', 1)
            ->assertJsonPath('data.bulan_ini.peringkat', 1)
            ->assertJsonPath('data.bulan_ini.total_poin', 10);
    }
}
