<?php

namespace Tests\Feature\Dashboard;

use App\Models\Desa;
use App\Models\Kabupaten;
use App\Models\Kader;
use App\Models\Kecamatan;
use App\Models\PatientsCache;
use App\Models\Puskesmas;
use App\Models\RiskClassification;
use App\Models\RiskTransitionScore;
use App\Models\User;
use App\Models\VisitAssignment;
use App\Models\VisitReport;
use App\Services\Performance\RiskTransitionScorer;
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

    public function test_total_patients_aktif_hanya_hitung_ringan_sedang_berat_bukan_tidak_berisiko(): void
    {
        // REVISI KELIMA -- "Total Pasien Aktif" (total_patients) HARUS cuma menghitung pasien
        // yang levelnya SEDANG berisiko (ringan/sedang/berat), BUKAN "punya klasifikasi apa
        // pun". Pasien tidak_berisiko (baik yang membaik maupun yang memang belum pernah
        // berisiko sama sekali, lihat RiskClassificationService REVISI KEEMPAT) TIDAK ikut
        // dihitung sebagai "aktif" -- selisihnya (total_patients_prolanis - total_patients)
        // adalah "Pasien Tidak Berisiko" murni lewat pengurangan, tanpa perlu formula gabungan.
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);

        $ringan = $this->makePatient($this->puskesmasA, 1);
        RiskClassification::create(['patient_id' => $ringan->id, 'level' => 'ringan', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        $sedang = $this->makePatient($this->puskesmasA, 2);
        RiskClassification::create(['patient_id' => $sedang->id, 'level' => 'sedang', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        $tidakBerisiko = $this->makePatient($this->puskesmasA, 3);
        RiskClassification::create(['patient_id' => $tidakBerisiko->id, 'level' => 'tidak_berisiko', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        // Belum pernah diklasifikasi sama sekali -- tetap masuk total_patients_prolanis, TAPI
        // bukan bagian dari total_patients (aktif) krn tidak ada baris ringan/sedang/berat.
        $this->makePatient($this->puskesmasA, 4);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $this->assertSame(2, $response->json('data.total_patients'), 'hanya ringan+sedang yang dihitung aktif');
        $this->assertSame(4, $response->json('data.total_patients_prolanis'));
    }

    public function test_super_admin_dapat_ringkasan_semua_puskesmas(): void
    {
        $superAdmin = $this->makeUser('super_admin');

        $patientA = $this->makePatient($this->puskesmasA, 1);
        $patientB = $this->makePatient($this->puskesmasB, 2);
        RiskClassification::create(['patient_id' => $patientA->id, 'level' => 'ringan', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);
        RiskClassification::create(['patient_id' => $patientB->id, 'level' => 'sedang', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

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
        RiskClassification::create(['patient_id' => $patientMilikKader->id, 'level' => 'ringan', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

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
        // Revisi Bu Kadis: kecamatan PRIMARY sekarang lewat puskesmas.kecamatan_id (bukan lagi
        // desa_id langsung, lihat DashboardService::risikoPerKecamatan()) -- wajib di-set di sini.
        $this->puskesmasA->update(['kecamatan_id' => $kecamatan->id]);

        $beratResolved = $this->makePatient($this->puskesmasA, 1);
        $beratResolved->update(['desa_id' => $desa->id, 'wilayah_status' => 'resolved']);
        RiskClassification::create(['patient_id' => $beratResolved->id, 'level' => 'berat', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        $ringanResolved = $this->makePatient($this->puskesmasA, 2);
        $ringanResolved->update(['desa_id' => $desa->id, 'wilayah_status' => 'resolved']);
        RiskClassification::create(['patient_id' => $ringanResolved->id, 'level' => 'ringan', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        // Pasien BENAR-BENAR tidak teridentifikasi (puskesmas_id null DAN kecamatan_id null) --
        // sengaja TIDAK boleh ikut muncul. BEDA dari sebelumnya (cuma desa_id null): sejak
        // kecamatan PRIMARY lewat puskesmas.kecamatan_id, seorang pasien yang puskesmas_id-nya
        // sudah resolved (misal lewat pengirim_matched/kecamatan_fallback) TETAP ikut terhitung
        // walau desa spesifiknya sendiri tidak diketahui -- exclusion cuma berlaku kalau puskesmas
        // MAUPUN kecamatan sama-sama tidak diketahui sama sekali.
        $fallback = $this->makePatient($this->puskesmasA, 3);
        $fallback->update(['puskesmas_id' => null, 'desa_id' => null, 'kecamatan_id' => null, 'wilayah_status' => 'unresolved']);
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

    /**
     * Bug nyata (dilaporkan user): admin_puskesmas Pandian melihat SEMUA kecamatan Kabupaten
     * Sumenep terwarnai di peta "Peta Sebaran Pasien Risiko" -- kebocoran data lintas puskesmas.
     * Root cause: risiko_per_kecamatan (dipakai peta, lihat dashboard/index.vue
     * refreshMapRiskData) SEBELUMNYA unscoped sama seperti leaderboard. Sekarang HARUS
     * ter-scope ke puskesmas_id admin sendiri, persis seperti risiko_per_desa.
     */
    public function test_risiko_per_kecamatan_untuk_peta_scoped_ke_puskesmas_sendiri(): void
    {
        $kabupaten = Kabupaten::first();
        $kecamatanA = Kecamatan::create(['kabupaten_id' => $kabupaten->id, 'kode_kemendagri' => 'K01', 'nama' => 'Kecamatan A']);
        $kecamatanB = Kecamatan::create(['kabupaten_id' => $kabupaten->id, 'kode_kemendagri' => 'K02', 'nama' => 'Kecamatan B']);
        $this->puskesmasA->update(['kecamatan_id' => $kecamatanA->id]);
        $this->puskesmasB->update(['kecamatan_id' => $kecamatanB->id]);

        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);

        $pasienA = $this->makePatient($this->puskesmasA, 1);
        RiskClassification::create(['patient_id' => $pasienA->id, 'level' => 'berat', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        $pasienB = $this->makePatient($this->puskesmasB, 2);
        RiskClassification::create(['patient_id' => $pasienB->id, 'level' => 'ringan', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $risiko = collect($response->json('data.risiko_per_kecamatan'))->keyBy('kecamatan_id');
        $this->assertCount(1, $risiko);
        $this->assertSame(1, $risiko[$kecamatanA->id]['berat']);
        $this->assertArrayNotHasKey($kecamatanB->id, $risiko);
    }

    /**
     * "Top 5 Kecamatan Risiko Tertinggi" TETAP leaderboard SE-KABUPATEN, sama untuk semua role
     * (revisi Bu Kadis) -- sekarang field TERPISAH (risiko_per_kecamatan_se_kabupaten) supaya
     * tidak lagi ikut kepakai diam-diam oleh peta (lihat test di atas).
     */
    public function test_risiko_per_kecamatan_se_kabupaten_tetap_unscoped_untuk_leaderboard(): void
    {
        $kabupaten = Kabupaten::first();
        $kecamatanA = Kecamatan::create(['kabupaten_id' => $kabupaten->id, 'kode_kemendagri' => 'K01', 'nama' => 'Kecamatan A']);
        $kecamatanB = Kecamatan::create(['kabupaten_id' => $kabupaten->id, 'kode_kemendagri' => 'K02', 'nama' => 'Kecamatan B']);
        $this->puskesmasA->update(['kecamatan_id' => $kecamatanA->id]);
        $this->puskesmasB->update(['kecamatan_id' => $kecamatanB->id]);

        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);

        $pasienA = $this->makePatient($this->puskesmasA, 1);
        RiskClassification::create(['patient_id' => $pasienA->id, 'level' => 'berat', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        $pasienB = $this->makePatient($this->puskesmasB, 2);
        RiskClassification::create(['patient_id' => $pasienB->id, 'level' => 'ringan', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $risiko = collect($response->json('data.risiko_per_kecamatan_se_kabupaten'))->keyBy('kecamatan_id');
        $this->assertCount(2, $risiko);
        $this->assertSame(1, $risiko[$kecamatanA->id]['berat']);
        $this->assertSame(1, $risiko[$kecamatanB->id]['ringan']);
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

    // ---- revisi Bu Kadis: risiko_per_puskesmas (peta mode 'puskesmas') ----

    public function test_risiko_per_puskesmas_unscoped_dan_menyertakan_koordinat(): void
    {
        $this->puskesmasA->update(['latitude' => -7.0123, 'longitude' => 113.8456]);
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);

        $beratA = $this->makePatient($this->puskesmasA, 1);
        RiskClassification::create(['patient_id' => $beratA->id, 'level' => 'berat', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        $tidakBerisikoA = $this->makePatient($this->puskesmasA, 2);
        RiskClassification::create(['patient_id' => $tidakBerisikoA->id, 'level' => 'tidak_berisiko', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        $ringanB = $this->makePatient($this->puskesmasB, 3);
        RiskClassification::create(['patient_id' => $ringanB->id, 'level' => 'ringan', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $risiko = collect($response->json('data.risiko_per_puskesmas'))->keyBy('puskesmas_id');

        // admin_puskesmas TETAP lihat SEMUA puskesmas (unscoped, sama pola leaderboard) --
        // bukan cuma puskesmasnya sendiri.
        $this->assertCount(2, $risiko);

        $a = $risiko[$this->puskesmasA->id];
        $this->assertSame('Puskesmas A', $a['puskesmas_nama']);
        $this->assertSame(-7.0123, $a['latitude']);
        $this->assertSame(113.8456, $a['longitude']);
        $this->assertSame(1, $a['berat']);
        $this->assertSame(1, $a['tidak_berisiko']);
        $this->assertSame(0, $a['ringan']);
        $this->assertSame(0, $a['sedang']);

        $b = $risiko[$this->puskesmasB->id];
        $this->assertSame(1, $b['ringan']);
        $this->assertNull($b['latitude']);
    }

    public function test_risiko_per_puskesmas_menyertakan_puskesmas_tanpa_pasien_sama_sekali(): void
    {
        $puskesmasKosong = Puskesmas::create(['kabupaten_id' => $this->puskesmasA->kabupaten_id, 'kode_internal' => 'PKM-C', 'nama' => 'Puskesmas Kosong']);
        $admin = $this->makeUser('super_admin');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $risiko = collect($response->json('data.risiko_per_puskesmas'))->keyBy('puskesmas_id');

        $this->assertCount(3, $risiko);
        $kosong = $risiko[$puskesmasKosong->id];
        $this->assertSame(0, $kosong['tidak_berisiko']);
        $this->assertSame(0, $kosong['ringan']);
        $this->assertSame(0, $kosong['sedang']);
        $this->assertSame(0, $kosong['berat']);
    }

    // ---- Sistem scoring kinerja puskesmas (Top 5) -- puskesmas_performance ----
    // Algoritma perhitungan poin/eligibilitas sendiri diuji tuntas di
    // Tests\Feature\Risk\RiskTransitionScorerTest -- test di sini fokus ke agregasi &
    // scoping level dashboard (memakai RiskTransitionScorer::score() apa adanya, bukan mock).

    /**
     * Bikin 1 transisi risk_classifications + skornya (lewat RiskTransitionScorer asli, bukan
     * langsung insert RiskTransitionScore -- supaya test ini tetap representatif kalau formula
     * poin berubah). $visitCreatedAt diisi utk membuat laporan kunjungan TERVALIDASI di antara
     * kedua assessment (bikin transisi ini eligible); null = tidak ada kunjungan sama sekali
     * (transisi tetap tercatat tapi eligible=false).
     */
    private function makeScoredTransition(
        PatientsCache $patient,
        Puskesmas $puskesmas,
        string $previousLevel,
        string $currentLevel,
        $previousAt,
        $currentAt,
        $visitCreatedAt = null,
        string $visitValidationStatus = 'valid',
    ): void {
        $previous = RiskClassification::create(['patient_id' => $patient->id, 'level' => $previousLevel, 'criteria_snapshot' => [], 'computed_at' => $previousAt, 'is_latest' => false]);
        $current = RiskClassification::create(['patient_id' => $patient->id, 'level' => $currentLevel, 'criteria_snapshot' => [], 'computed_at' => $currentAt, 'is_latest' => true]);

        if ($visitCreatedAt !== null) {
            $assignment = VisitAssignment::create([
                'patient_id' => $patient->id, 'kader_id' => $this->makeKader($puskesmas)->id,
                'scheduled_date' => $visitCreatedAt->toDateString(), 'status' => 'completed', 'priority' => 'sedang',
                'puskesmas_id_snapshot' => $puskesmas->id,
            ]);
            $report = VisitReport::create([
                'assignment_id' => $assignment->id,
                'gps_lat' => -7.0, 'gps_lng' => 113.0,
                'photo_path' => 'test-photo.jpg',
                'kondisi' => 'Baik',
                'validation_status' => $visitValidationStatus,
            ]);
            $report->forceFill(['created_at' => $visitCreatedAt])->save();
        }

        app(RiskTransitionScorer::class)->score($patient, $previous, $current);
    }

    public function test_puskesmas_performance_transisi_membaik_dengan_kunjungan_tervalidasi_masuk_leaderboard(): void
    {
        $admin = $this->makeUser('super_admin');
        $pasien = $this->makePatient($this->puskesmasA, 1);

        // Berat (hari -10) -> Sedang (hari -2), dengan kunjungan tervalidasi di hari -5 --
        // eligible, poin = (3-2)*10 = +10.
        $this->makeScoredTransition(
            $pasien, $this->puskesmasA,
            'berat', 'sedang', now()->subDays(10), now()->subDays(2), now()->subDays(5),
        );

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $performance = collect($response->json('data.puskesmas_performance'));
        $this->assertCount(1, $performance);

        $row = $performance->first();
        $this->assertSame(1, $row['rank']);
        $this->assertSame($this->puskesmasA->id, $row['puskesmas_id']);
        $this->assertSame('Puskesmas A', $row['puskesmas_nama']);
        $this->assertSame(1, $row['eligible_patients']);
        $this->assertSame(1, $row['improved_patients']);
        $this->assertSame(1, $row['validated_visits']);
        $this->assertSame(10, $row['total_improvement_points']);
        $this->assertEquals(100.0, $row['improvement_rate']);
        $this->assertGreaterThan(0, $row['final_score']);
    }

    public function test_puskesmas_performance_transisi_tanpa_kunjungan_tervalidasi_tidak_masuk_leaderboard(): void
    {
        // Spesifikasi scoring: kunjungan pending TIDAK dianggap bukti intervensi -- transisi
        // tetap tercatat di risk_transition_scores (audit trail) tapi eligible=false, TIDAK
        // masuk agregasi kinerja puskesmas sama sekali.
        $admin = $this->makeUser('super_admin');
        $pasien = $this->makePatient($this->puskesmasA, 1);

        $this->makeScoredTransition(
            $pasien, $this->puskesmasA,
            'berat', 'sedang', now()->subDays(10), now()->subDays(2), now()->subDays(5),
            visitValidationStatus: 'pending',
        );

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $this->assertSame([], $response->json('data.puskesmas_performance'));
    }

    public function test_puskesmas_performance_transisi_memburuk_tidak_dihitung_sebagai_improved(): void
    {
        $admin = $this->makeUser('super_admin');
        $pasien = $this->makePatient($this->puskesmasA, 1);

        // Sedang -> Berat (MEMBURUK), tetap eligible (ada kunjungan tervalidasi) tapi
        // final_point negatif -- tidak boleh dihitung sebagai improved_patients.
        $this->makeScoredTransition(
            $pasien, $this->puskesmasA,
            'sedang', 'berat', now()->subDays(10), now()->subDays(2), now()->subDays(5),
        );

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $performance = collect($response->json('data.puskesmas_performance'));
        $this->assertCount(1, $performance);
        $row = $performance->first();
        $this->assertSame(1, $row['eligible_patients']);
        $this->assertSame(0, $row['improved_patients']);
        $this->assertSame(-10, $row['total_improvement_points']);
        $this->assertEquals(0.0, $row['improvement_rate']);
    }

    public function test_puskesmas_performance_terkendali_ke_terkendali_dihitung_retensi(): void
    {
        // Terkendali->Terkendali = +2 (bukan 0) & masuk numerator stability_rate.
        $admin = $this->makeUser('super_admin');
        $pasien = $this->makePatient($this->puskesmasA, 1);

        $this->makeScoredTransition(
            $pasien, $this->puskesmasA,
            'tidak_berisiko', 'tidak_berisiko', now()->subDays(10), now()->subDays(2), now()->subDays(5),
        );

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $row = collect($response->json('data.puskesmas_performance'))->first();
        $this->assertSame(2, $row['total_improvement_points']);
        $this->assertEquals(100.0, $row['stability_rate']);
    }

    public function test_puskesmas_performance_unscoped_untuk_semua_role(): void
    {
        // Leaderboard SE-KABUPATEN, sama untuk semua role (konsisten dengan perilaku lama) --
        // admin_puskesmas TETAP melihat puskesmas lain di sini, bukan cuma puskesmasnya sendiri.
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);

        $pasienA = $this->makePatient($this->puskesmasA, 1);
        $this->makeScoredTransition($pasienA, $this->puskesmasA, 'berat', 'sedang', now()->subDays(10), now()->subDays(2), now()->subDays(5));

        $pasienB = $this->makePatient($this->puskesmasB, 2);
        $this->makeScoredTransition($pasienB, $this->puskesmasB, 'ringan', 'tidak_berisiko', now()->subDays(10), now()->subDays(2), now()->subDays(5));

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $puskesmasIds = collect($response->json('data.puskesmas_performance'))->pluck('puskesmas_id')->all();
        $this->assertContains($this->puskesmasA->id, $puskesmasIds);
        $this->assertContains($this->puskesmasB->id, $puskesmasIds);
    }

    public function test_puskesmas_performance_difilter_calculated_at_dalam_periode(): void
    {
        $admin = $this->makeUser('super_admin');
        $pasien = $this->makePatient($this->puskesmasA, 1);

        $this->makeScoredTransition($pasien, $this->puskesmasA, 'berat', 'sedang', now()->subDays(30), now()->subDays(20), now()->subDays(25));
        // calculated_at (kapan skor DIHITUNG oleh RiskTransitionScorer, BUKAN kapan assessment
        // medisnya terjadi) selalu "now()" saat score() dipanggil di atas -- paksa mundur secara
        // eksplisit di sini supaya benar-benar menguji filter date_from/date_to, bukan kebetulan
        // lolos karena test itu sendiri baru saja jalan.
        RiskTransitionScore::query()->update(['calculated_at' => now()->subDays(20)]);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/dashboard/summary?date_from='.now()->subDays(7)->toDateString().'&date_to='.now()->toDateString());

        $response->assertOk();
        $this->assertSame([], $response->json('data.puskesmas_performance'));
    }

    public function test_puskesmas_performance_kosong_kalau_belum_ada_transisi_sama_sekali(): void
    {
        $admin = $this->makeUser('super_admin');

        // Assessment PERTAMA pasien (tidak ada pembanding) -- tidak menghasilkan baris skor
        // sama sekali, bukan "membaik".
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

    // ---- Fitur baru: kecamatan_context (caption peta "Data untuk Puskesmas X di Kecamatan Y") ----

    public function test_kecamatan_context_muncul_kalau_kecamatan_punya_lebih_dari_1_puskesmas(): void
    {
        $kabupaten = Kabupaten::first();
        $kotaSumenep = Kecamatan::create(['kabupaten_id' => $kabupaten->id, 'kode_kemendagri' => 'K17', 'nama' => 'Kota Sumenep']);
        $this->puskesmasA->update(['kecamatan_id' => $kotaSumenep->id]);
        $this->puskesmasB->update(['kecamatan_id' => $kotaSumenep->id]);

        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $this->assertSame([
            'puskesmas_nama' => 'Puskesmas A',
            'kecamatan_nama' => 'Kota Sumenep',
            'kecamatan_puskesmas_count' => 2,
        ], $response->json('data.kecamatan_context'));
    }

    public function test_kecamatan_context_null_kalau_kecamatan_cuma_1_puskesmas(): void
    {
        $kabupaten = Kabupaten::first();
        $manding = Kecamatan::create(['kabupaten_id' => $kabupaten->id, 'kode_kemendagri' => 'K05', 'nama' => 'Manding']);
        $this->puskesmasA->update(['kecamatan_id' => $manding->id]);

        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $this->assertNull($response->json('data.kecamatan_context'));
    }

    public function test_kecamatan_context_null_untuk_super_admin_tanpa_filter_puskesmas(): void
    {
        $admin = $this->makeUser('super_admin');
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertOk();
        $this->assertNull($response->json('data.kecamatan_context'));
    }

    public function test_kecamatan_context_muncul_untuk_super_admin_yang_filter_ke_1_puskesmas(): void
    {
        $kabupaten = Kabupaten::first();
        $kotaSumenep = Kecamatan::create(['kabupaten_id' => $kabupaten->id, 'kode_kemendagri' => 'K17', 'nama' => 'Kota Sumenep']);
        $this->puskesmasA->update(['kecamatan_id' => $kotaSumenep->id]);
        $this->puskesmasB->update(['kecamatan_id' => $kotaSumenep->id]);

        $admin = $this->makeUser('super_admin');
        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/dashboard/summary?puskesmas_id={$this->puskesmasA->id}");

        $response->assertOk();
        $this->assertSame(2, $response->json('data.kecamatan_context.kecamatan_puskesmas_count'));
    }

    public function test_tanpa_login_ditolak_401(): void
    {
        $response = $this->getJson('/api/v1/dashboard/summary');

        $response->assertStatus(401);
    }
}
