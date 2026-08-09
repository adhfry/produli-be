<?php

namespace Tests\Feature\Dashboard;

use App\Models\Desa;
use App\Models\Kabupaten;
use App\Models\Kader;
use App\Models\Kecamatan;
use App\Models\PatientsCache;
use App\Models\Puskesmas;
use App\Models\RiskClassification;
use App\Models\User;
use App\Models\VisitAssignment;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresi untuk GET /api/v1/dashboard/summary (docs/planning/02 §7/§13) -- jumlah pasien per
 * level risiko & jumlah kunjungan per status (di-scope sama seperti GET /api/v1/patients),
 * plus perluasan §13: kader_aktif_count, tingkat_kepatuhan, aktivitas_hari_ini,
 * risiko_per_kecamatan.
 */
class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    private Puskesmas $puskesmasA;

    private Puskesmas $puskesmasB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $this->puskesmasA = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-A', 'nama' => 'Puskesmas A']);
        $this->puskesmasB = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-B', 'nama' => 'Puskesmas B']);
    }

    private function makeUser(string $role, ?Puskesmas $puskesmas = null): User
    {
        $user = User::factory()->create(['puskesmas_id' => $puskesmas?->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makePatient(Puskesmas $puskesmas, int $externalId): PatientsCache
    {
        return PatientsCache::create([
            'external_patient_id' => $externalId,
            'nik_hash' => 'HASH-'.$externalId,
            'nama' => 'Pasien '.$externalId,
            'puskesmas_id' => $puskesmas->id,
            'wilayah_status' => 'unknown',
        ]);
    }

    public function test_admin_puskesmas_hanya_dapat_ringkasan_puskesmas_sendiri(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);

        $beratA = $this->makePatient($this->puskesmasA, 1);
        $ringanA = $this->makePatient($this->puskesmasA, 2);
        $beratB = $this->makePatient($this->puskesmasB, 3);

        RiskClassification::create(['patient_id' => $beratA->id, 'level' => 'berat', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);
        RiskClassification::create(['patient_id' => $ringanA->id, 'level' => 'ringan', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);
        RiskClassification::create(['patient_id' => $beratB->id, 'level' => 'berat', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        VisitAssignment::create([
            'patient_id' => $beratA->id, 'kader_id' => $this->makeKader($this->puskesmasA)->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'pending', 'priority' => 'berat',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);
        VisitAssignment::create([
            'patient_id' => $beratB->id, 'kader_id' => $this->makeKader($this->puskesmasB)->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'completed', 'priority' => 'berat',
            'puskesmas_id_snapshot' => $this->puskesmasB->id,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $this->assertSame(2, $response->json('data.total_patients'));
        $this->assertSame(1, $response->json('data.patients_per_risk_level.berat'));
        $this->assertSame(1, $response->json('data.patients_per_risk_level.ringan'));
        $this->assertSame(0, $response->json('data.patients_per_risk_level.sedang'));
        $this->assertSame(1, $response->json('data.total_assignments'));
        $this->assertSame(1, $response->json('data.visits_per_status.pending'));
        $this->assertSame(0, $response->json('data.visits_per_status.completed'));
    }

    public function test_super_admin_dapat_ringkasan_semua_puskesmas(): void
    {
        $superAdmin = $this->makeUser('super_admin');

        $this->makePatient($this->puskesmasA, 1);
        $this->makePatient($this->puskesmasB, 2);

        Sanctum::actingAs($superAdmin);

        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $this->assertSame(2, $response->json('data.total_patients'));
    }

    public function test_kader_hanya_dapat_ringkasan_assignment_miliknya(): void
    {
        $kaderUser = $this->makeUser('kader', $this->puskesmasA);
        $kader = Kader::create(['user_id' => $kaderUser->id, 'puskesmas_id' => $this->puskesmasA->id, 'status_aktif' => true]);
        $kaderLain = $this->makeKader($this->puskesmasA);

        $patientMilikKader = $this->makePatient($this->puskesmasA, 1);
        $patientMilikKaderLain = $this->makePatient($this->puskesmasA, 2);

        VisitAssignment::create([
            'patient_id' => $patientMilikKader->id, 'kader_id' => $kader->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'pending', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);
        VisitAssignment::create([
            'patient_id' => $patientMilikKaderLain->id, 'kader_id' => $kaderLain->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'pending', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);

        Sanctum::actingAs($kaderUser);

        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.total_patients'));
        $this->assertSame(1, $response->json('data.total_assignments'));
    }

    // ---- §13: kader_aktif_count & tingkat_kepatuhan ----

    public function test_kader_aktif_count_dan_tingkat_kepatuhan(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);

        $kaderAktif1 = $this->makeKader($this->puskesmasA, true);
        $kaderAktif2 = $this->makeKader($this->puskesmasA, true);
        $this->makeKader($this->puskesmasA, false); // tidak aktif -- tidak boleh ikut terhitung
        $this->makeKader($this->puskesmasB, true); // beda puskesmas -- tidak boleh ikut terhitung

        $patient1 = $this->makePatient($this->puskesmasA, 1);
        $patient2 = $this->makePatient($this->puskesmasA, 2);
        $patient3 = $this->makePatient($this->puskesmasA, 3);
        $patient4 = $this->makePatient($this->puskesmasA, 4);

        foreach ([[$patient1, $kaderAktif1, 'completed'], [$patient2, $kaderAktif1, 'completed'], [$patient3, $kaderAktif2, 'pending'], [$patient4, $kaderAktif2, 'pending']] as [$patient, $kader, $status]) {
            VisitAssignment::create([
                'patient_id' => $patient->id, 'kader_id' => $kader->id,
                'scheduled_date' => now()->toDateString(), 'status' => $status, 'priority' => 'sedang',
                'puskesmas_id_snapshot' => $this->puskesmasA->id,
            ]);
        }

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $this->assertSame(2, $response->json('data.kader_aktif_count'));
        // JSON tidak membedakan float bulat dari int (50.0 -> 50 lewat encode) -- assertEquals.
        $this->assertEquals(50.0, $response->json('data.tingkat_kepatuhan')); // 2 completed / 4 total
    }

    public function test_tingkat_kepatuhan_nol_kalau_belum_ada_assignment(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $this->assertEquals(0.0, $response->json('data.tingkat_kepatuhan'));
    }

    public function test_kader_hanya_lihat_dirinya_sendiri_di_kader_aktif_count(): void
    {
        $kaderUser = $this->makeUser('kader', $this->puskesmasA);
        Kader::create(['user_id' => $kaderUser->id, 'puskesmas_id' => $this->puskesmasA->id, 'status_aktif' => true]);
        $this->makeKader($this->puskesmasA, true); // kolega -- tidak boleh ikut terhitung

        Sanctum::actingAs($kaderUser);

        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.kader_aktif_count'));
    }

    // ---- §13: aktivitas_hari_ini ----

    public function test_aktivitas_hari_ini_per_kader_termasuk_yang_tanpa_target(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);

        $kaderSibuk = $this->makeKader($this->puskesmasA, true, 'Bu Sibuk');
        $kaderKosong = $this->makeKader($this->puskesmasA, true, 'Bu Kosong');

        $patient1 = $this->makePatient($this->puskesmasA, 1);
        $patient2 = $this->makePatient($this->puskesmasA, 2);

        VisitAssignment::create([
            'patient_id' => $patient1->id, 'kader_id' => $kaderSibuk->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'completed', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);
        VisitAssignment::create([
            'patient_id' => $patient2->id, 'kader_id' => $kaderSibuk->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'pending', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);
        // Assignment BESOK -- tidak boleh ikut terhitung sebagai target hari ini.
        VisitAssignment::create([
            'patient_id' => $patient2->id, 'kader_id' => $kaderKosong->id,
            'scheduled_date' => now()->addDay()->toDateString(), 'status' => 'pending', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $aktivitas = collect($response->json('data.aktivitas_hari_ini'))->keyBy('kader_id');

        $sibuk = $aktivitas[$kaderSibuk->id];
        $this->assertSame('Bu Sibuk', $sibuk['nama']);
        $this->assertSame(2, $sibuk['target_hari_ini']);
        $this->assertSame(1, $sibuk['selesai_hari_ini']);
        $this->assertNotNull($sibuk['last_update_at']);

        $kosong = $aktivitas[$kaderKosong->id];
        $this->assertSame('Bu Kosong', $kosong['nama']);
        $this->assertSame(0, $kosong['target_hari_ini']);
        $this->assertSame(0, $kosong['selesai_hari_ini']);
        $this->assertNull($kosong['last_update_at']);
    }

    // ---- §13: risiko_per_kecamatan ----

    public function test_risiko_per_kecamatan_agregat_dari_pasien_dengan_desa_resolved(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);

        $kabupaten = Kabupaten::first();
        $kecamatan = Kecamatan::create(['kabupaten_id' => $kabupaten->id, 'kode_kemendagri' => 'K01', 'nama' => 'Talango']);
        $desa = Desa::create(['kecamatan_id' => $kecamatan->id, 'puskesmas_id' => $this->puskesmasA->id, 'kode_kemendagri' => 'D01', 'nama' => 'Talango']);

        $beratResolved = $this->makePatient($this->puskesmasA, 1);
        $beratResolved->update(['desa_id' => $desa->id, 'wilayah_status' => 'resolved']);
        RiskClassification::create(['patient_id' => $beratResolved->id, 'level' => 'berat', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        $ringanResolved = $this->makePatient($this->puskesmasA, 2);
        $ringanResolved->update(['desa_id' => $desa->id, 'wilayah_status' => 'resolved']);
        RiskClassification::create(['patient_id' => $ringanResolved->id, 'level' => 'ringan', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        // Pasien kecamatan_fallback (desa_id NULL) -- sengaja TIDAK boleh ikut muncul, karena
        // kecamatan pastinya tidak diketahui cukup presisi.
        $fallback = $this->makePatient($this->puskesmasA, 3);
        $fallback->update(['desa_id' => null, 'wilayah_status' => 'unresolved']);
        RiskClassification::create(['patient_id' => $fallback->id, 'level' => 'berat', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $risiko = collect($response->json('data.risiko_per_kecamatan'));
        $this->assertCount(1, $risiko);

        $row = $risiko->first();
        $this->assertSame($kecamatan->id, $row['kecamatan_id']);
        $this->assertSame('Talango', $row['kecamatan_nama']);
        $this->assertSame('K01', $row['kecamatan_kode']);
        $this->assertSame(1, $row['berat']); // fallback TIDAK ikut terhitung
        $this->assertSame(1, $row['ringan']);
        $this->assertSame(0, $row['sedang']);
    }

    // ---- §17: risiko_per_desa ----

    public function test_risiko_per_desa_agregat_hanya_wilayah_status_resolved(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);

        $kabupaten = Kabupaten::first();
        $kecamatan = Kecamatan::create(['kabupaten_id' => $kabupaten->id, 'kode_kemendagri' => 'K01', 'nama' => 'Talango']);
        $desaSatu = Desa::create(['kecamatan_id' => $kecamatan->id, 'puskesmas_id' => $this->puskesmasA->id, 'kode_kemendagri' => 'D01', 'nama' => 'Talango Barat']);
        $desaDua = Desa::create(['kecamatan_id' => $kecamatan->id, 'puskesmas_id' => $this->puskesmasA->id, 'kode_kemendagri' => 'D02', 'nama' => 'Talango Timur']);

        $beratDesaSatu = $this->makePatient($this->puskesmasA, 1);
        $beratDesaSatu->update(['desa_id' => $desaSatu->id, 'wilayah_status' => 'resolved']);
        RiskClassification::create(['patient_id' => $beratDesaSatu->id, 'level' => 'berat', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        $ringanDesaSatu = $this->makePatient($this->puskesmasA, 2);
        $ringanDesaSatu->update(['desa_id' => $desaSatu->id, 'wilayah_status' => 'resolved']);
        RiskClassification::create(['patient_id' => $ringanDesaSatu->id, 'level' => 'ringan', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        $sedangDesaDua = $this->makePatient($this->puskesmasA, 3);
        $sedangDesaDua->update(['desa_id' => $desaDua->id, 'wilayah_status' => 'resolved']);
        RiskClassification::create(['patient_id' => $sedangDesaDua->id, 'level' => 'sedang', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        // kecamatan_fallback (desa_id NULL, wilayah_status=unresolved) -- beda dari
        // risiko_per_kecamatan, level DESA butuh presisi lebih tinggi, jadi ini TIDAK ikut
        // muncul di risiko_per_desa meski kecamatan-nya sendiri diketahui.
        $fallback = $this->makePatient($this->puskesmasA, 4);
        $fallback->update(['desa_id' => null, 'wilayah_status' => 'unresolved', 'puskesmas_resolution_method' => 'kecamatan_fallback']);
        RiskClassification::create(['patient_id' => $fallback->id, 'level' => 'berat', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $risiko = collect($response->json('data.risiko_per_desa'))->keyBy('desa_id');
        $this->assertCount(2, $risiko);

        $satu = $risiko[$desaSatu->id];
        $this->assertSame('Talango Barat', $satu['desa_nama']);
        $this->assertSame('D01', $satu['desa_kode']);
        $this->assertSame(1, $satu['berat']); // fallback TIDAK ikut terhitung
        $this->assertSame(1, $satu['ringan']);
        $this->assertSame(0, $satu['sedang']);

        $dua = $risiko[$desaDua->id];
        $this->assertSame('Talango Timur', $dua['desa_nama']);
        $this->assertSame(0, $dua['berat']);
        $this->assertSame(0, $dua['ringan']);
        $this->assertSame(1, $dua['sedang']);
    }

    private function makeKader(Puskesmas $puskesmas, bool $aktif = true, ?string $nama = null): Kader
    {
        $user = User::factory()->create(['puskesmas_id' => $puskesmas->id, 'name' => $nama ?? 'Kader Uji']);

        return Kader::create(['user_id' => $user->id, 'puskesmas_id' => $puskesmas->id, 'status_aktif' => $aktif]);
    }

    public function test_tanpa_login_ditolak_401(): void
    {
        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertStatus(401);
    }
}
