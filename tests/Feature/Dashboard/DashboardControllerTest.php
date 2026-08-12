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

    public function test_total_patients_prolanis_hitung_semua_pasien_scope_termasuk_yang_belum_pernah_diklasifikasi(): void
    {
        // Revisi Bu Kadis -- "3.900 dari total 5.000 pasien Prolanis": total_patients_prolanis
        // HARUS ikut menghitung pasien yang belum pernah punya baris risk_classifications sama
        // sekali (lolos eligibility SyncSilakesService tapi tidak ada parameter yang pernah
        // melebihi ambang) -- beda dari total_patients yang cuma menghitung yang efektif
        // terklasifikasi.
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);

        $terklasifikasi = $this->makePatient($this->puskesmasA, 1);
        RiskClassification::create(['patient_id' => $terklasifikasi->id, 'level' => 'ringan', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        // Belum pernah diklasifikasi sama sekali -- tetap harus ikut terhitung sebagai bagian
        // dari "total pasien Prolanis" puskesmas ini.
        $this->makePatient($this->puskesmasA, 2);

        // Beda puskesmas -- TIDAK boleh ikut terhitung (admin_puskesmas terkunci puskesmasnya sendiri).
        $this->makePatient($this->puskesmasB, 3);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.total_patients'));
        $this->assertSame(2, $response->json('data.total_patients_prolanis'));
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

    public function test_risiko_per_kecamatan_primary_dari_puskesmas_jumlah_dua_puskesmas_satu_kecamatan(): void
    {
        // Revisi: kecamatan PRIMARY sekarang dari puskesmas.kecamatan_id (via patients_cache.
        // puskesmas_id), bukan lagi patients_cache.kecamatan_id -- Puskesmas Pandian & Puskesmas
        // Pamolokan (contoh user) sama-sama di Kecamatan Kota Sumenep, harus dijumlah jadi 1 baris.
        $admin = $this->makeUser('super_admin');
        $kabupaten = Kabupaten::first();
        $kotaSumenep = Kecamatan::create(['kabupaten_id' => $kabupaten->id, 'kode_kemendagri' => 'K17', 'nama' => 'Kota Sumenep']);

        $pandian = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kecamatan_id' => $kotaSumenep->id, 'kode_internal' => 'PKM-PANDIAN', 'nama' => 'Puskesmas Pandian']);
        $pamolokan = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kecamatan_id' => $kotaSumenep->id, 'kode_internal' => 'PKM-PAMOLOKAN', 'nama' => 'Puskesmas Pamolokan']);

        $pasienPandian = $this->makePatient($pandian, 1);
        RiskClassification::create(['patient_id' => $pasienPandian->id, 'level' => 'berat', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        $pasienPamolokan = $this->makePatient($pamolokan, 2);
        RiskClassification::create(['patient_id' => $pasienPamolokan->id, 'level' => 'ringan', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $risiko = collect($response->json('data.risiko_per_kecamatan'))->keyBy('kecamatan_id');
        $this->assertCount(1, $risiko);

        $row = $risiko[$kotaSumenep->id];
        $this->assertSame('Kota Sumenep', $row['kecamatan_nama']);
        $this->assertSame(1, $row['berat']);
        $this->assertSame(1, $row['ringan']);
    }

    public function test_risiko_per_kecamatan_fallback_ke_kecamatan_raw_saat_puskesmas_null(): void
    {
        // Rujukan perorangan (pengirim_individual) atau unresolvable -- puskesmas_id null,
        // tapi kecamatan pasien MASIH bisa diketahui lewat kecamatan_raw (patients_cache.
        // kecamatan_id, hasil WilayahResolver terpisah dari resolusi puskesmas).
        $admin = $this->makeUser('super_admin');
        $kabupaten = Kabupaten::first();
        $talango = Kecamatan::create(['kabupaten_id' => $kabupaten->id, 'kode_kemendagri' => 'K28', 'nama' => 'Talango']);

        $pasien = PatientsCache::create([
            'external_patient_id' => 999,
            'nik_hash' => 'HASH-999',
            'nama' => 'Pasien Rujukan Dokter',
            'puskesmas_id' => null,
            'puskesmas_resolution_method' => 'pengirim_individual',
            'kecamatan_id' => $talango->id,
            'wilayah_status' => 'unknown',
        ]);
        RiskClassification::create(['patient_id' => $pasien->id, 'level' => 'sedang', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $risiko = collect($response->json('data.risiko_per_kecamatan'))->keyBy('kecamatan_id');
        $this->assertCount(1, $risiko);
        $this->assertSame(1, $risiko[$talango->id]['sedang']);
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

    // ---- Fase 4 (revisi Bu Kadis): puskesmas_performance ----

    public function test_puskesmas_performance_menghitung_pasien_yang_membaik_dikelompokkan_per_puskesmas(): void
    {
        $admin = $this->makeUser('super_admin');

        // Pasien A: berat -> sedang (membaik 1 tingkat) di Puskesmas A.
        $pasienA = $this->makePatient($this->puskesmasA, 1);
        RiskClassification::create(['patient_id' => $pasienA->id, 'level' => 'berat', 'criteria_snapshot' => [], 'computed_at' => now()->subDays(10), 'is_latest' => false]);
        RiskClassification::create(['patient_id' => $pasienA->id, 'level' => 'sedang', 'criteria_snapshot' => [], 'computed_at' => now()->subDays(2), 'is_latest' => true]);

        // Pasien B: sedang -> berat (MEMBURUK, tidak boleh terhitung) di Puskesmas A.
        $pasienB = $this->makePatient($this->puskesmasA, 2);
        RiskClassification::create(['patient_id' => $pasienB->id, 'level' => 'sedang', 'criteria_snapshot' => [], 'computed_at' => now()->subDays(10), 'is_latest' => false]);
        RiskClassification::create(['patient_id' => $pasienB->id, 'level' => 'berat', 'criteria_snapshot' => [], 'computed_at' => now()->subDays(2), 'is_latest' => true]);

        // Pasien C: ringan -> tidak_berisiko (membaik total) di Puskesmas B.
        $pasienC = $this->makePatient($this->puskesmasB, 3);
        RiskClassification::create(['patient_id' => $pasienC->id, 'level' => 'ringan', 'criteria_snapshot' => [], 'computed_at' => now()->subDays(10), 'is_latest' => false]);
        RiskClassification::create(['patient_id' => $pasienC->id, 'level' => 'tidak_berisiko', 'criteria_snapshot' => [], 'computed_at' => now()->subDays(2), 'is_latest' => true]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $performance = collect($response->json('data.puskesmas_performance'))->keyBy('puskesmas_id');
        $this->assertCount(2, $performance);

        $puskesmasA = $performance[$this->puskesmasA->id];
        $this->assertSame('Puskesmas A', $puskesmasA['puskesmas_nama']);
        $this->assertSame(1, $puskesmasA['total_membaik']);
        $this->assertSame(1, $puskesmasA['breakdown']['berat_ke_sedang']);
        $this->assertArrayNotHasKey('sedang_ke_berat', $puskesmasA['breakdown']);

        $puskesmasB = $performance[$this->puskesmasB->id];
        $this->assertSame(1, $puskesmasB['total_membaik']);
        $this->assertSame(1, $puskesmasB['breakdown']['ringan_ke_tidak_berisiko']);
    }

    public function test_puskesmas_performance_admin_puskesmas_hanya_lihat_puskesmas_sendiri(): void
    {
        // "Kalau data dipersonalisasi ke puskesmas untuk admin_puskesmas/pj_prolanis" -- HANYA
        // baris puskesmas sendiri yang boleh muncul, tidak pernah bocor ke puskesmas lain
        // walau puskesmas lain itu juga punya pasien yang membaik.
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);

        $pasienA = $this->makePatient($this->puskesmasA, 1);
        RiskClassification::create(['patient_id' => $pasienA->id, 'level' => 'berat', 'criteria_snapshot' => [], 'computed_at' => now()->subDays(10), 'is_latest' => false]);
        RiskClassification::create(['patient_id' => $pasienA->id, 'level' => 'sedang', 'criteria_snapshot' => [], 'computed_at' => now()->subDays(2), 'is_latest' => true]);

        $pasienB = $this->makePatient($this->puskesmasB, 2);
        RiskClassification::create(['patient_id' => $pasienB->id, 'level' => 'ringan', 'criteria_snapshot' => [], 'computed_at' => now()->subDays(10), 'is_latest' => false]);
        RiskClassification::create(['patient_id' => $pasienB->id, 'level' => 'tidak_berisiko', 'criteria_snapshot' => [], 'computed_at' => now()->subDays(2), 'is_latest' => true]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $performance = collect($response->json('data.puskesmas_performance'));
        $this->assertCount(1, $performance);
        $this->assertSame($this->puskesmasA->id, $performance->first()['puskesmas_id']);
    }

    public function test_puskesmas_performance_difilter_computed_at_baris_baru_dalam_periode(): void
    {
        $admin = $this->makeUser('super_admin');

        $pasien = $this->makePatient($this->puskesmasA, 1);
        RiskClassification::create(['patient_id' => $pasien->id, 'level' => 'berat', 'criteria_snapshot' => [], 'computed_at' => now()->subDays(20), 'is_latest' => false]);
        // Perbaikan tercatat 20 hari lalu -- DI LUAR periode filter di bawah (7 hari terakhir).
        RiskClassification::create(['patient_id' => $pasien->id, 'level' => 'sedang', 'criteria_snapshot' => [], 'computed_at' => now()->subDays(19), 'is_latest' => true]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard/summary?date_from='.now()->subDays(7)->toDateString().'&date_to='.now()->toDateString());

        $response->assertOk();
        $this->assertCount(0, $response->json('data.puskesmas_performance'));
    }

    public function test_puskesmas_performance_kosong_kalau_tidak_ada_yang_membaik(): void
    {
        $admin = $this->makeUser('super_admin');

        $pasien = $this->makePatient($this->puskesmasA, 1);
        RiskClassification::create(['patient_id' => $pasien->id, 'level' => 'sedang', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $this->assertSame([], $response->json('data.puskesmas_performance'));
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
