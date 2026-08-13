<?php

namespace Tests\Feature\Patient;

use App\Http\Controllers\Api\V1\PatientController;
use App\Models\Kabupaten;
use App\Models\Kader;
use App\Models\Kecamatan;
use App\Models\LabResultCache;
use App\Models\PatientsCache;
use App\Models\Puskesmas;
use App\Models\RiskClassification;
use App\Models\User;
use App\Models\VisitAssignment;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresi untuk GET /api/v1/patients (list+filter) dan GET /api/v1/patients/{id} (docs/planning/02
 * §7) -- scoping data per role HARUS konsisten dengan PatientsCachePolicy/ScopesByPuskesmas
 * (lihat tests/Feature/Policies/DataScopingPolicyTest.php), sekarang lewat jalur HTTP asli.
 */
class PatientControllerTest extends TestCase
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

    private function makePatient(Puskesmas $puskesmas, int $externalId, array $overrides = []): PatientsCache
    {
        return PatientsCache::create(array_merge([
            'external_patient_id' => $externalId,
            'nik_hash' => 'HASH-'.$externalId,
            'nama' => 'Pasien '.$externalId,
            'puskesmas_id' => $puskesmas->id,
            'wilayah_status' => 'unknown',
        ], $overrides));
    }

    public function test_admin_puskesmas_hanya_melihat_pasien_puskesmas_sendiri(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $patientA = $this->makePatient($this->puskesmasA, 1);
        $this->makePatient($this->puskesmasB, 2);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/patients');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertEquals([$patientA->id], $ids->all());
        $this->assertSame(1, $response->json('data.pagination.total'));
    }

    public function test_kader_murni_hanya_melihat_pasien_yang_ditugaskan_kepadanya(): void
    {
        $kaderUser = $this->makeUser('kader', $this->puskesmasA);
        $kader = Kader::create(['user_id' => $kaderUser->id, 'puskesmas_id' => $this->puskesmasA->id, 'status_aktif' => true]);

        $patientDitugaskan = $this->makePatient($this->puskesmasA, 1);
        $this->makePatient($this->puskesmasA, 2);

        VisitAssignment::create([
            'patient_id' => $patientDitugaskan->id,
            'kader_id' => $kader->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'pending',
            'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);

        Sanctum::actingAs($kaderUser);

        $response = $this->getJson('/api/v1/patients');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertEquals([$patientDitugaskan->id], $ids->all());
    }

    public function test_response_tidak_menyertakan_nik_hash(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $this->makePatient($this->puskesmasA, 1);

        Sanctum::actingAs($superAdmin);

        $response = $this->getJson('/api/v1/patients');

        $response->assertOk();
        $this->assertArrayNotHasKey('nik_hash', $response->json('data.items.0'));
    }

    /**
     * NIK asli (patients_cache.nik, permintaan Kepala Dinas) ditampilkan di dashboard/pasien +
     * detail pasien, tapi SELALU lewat App\Support\NikDisplay::resolve() -- lihat
     * NikDisplayTest untuk aturan mask lengkap (3529-prefix). Regresi ini menjaga API tidak
     * pernah mengembalikan NIK mentah tanpa lewat aturan mask itu.
     */
    public function test_response_menyertakan_nik_yang_sudah_di_mask(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $sumenep = $this->makePatient($this->puskesmasA, 1, ['nik' => '3529010101800001']);
        $luarKode = $this->makePatient($this->puskesmasA, 2, ['nik' => '3510010101800002']);

        Sanctum::actingAs($superAdmin);

        $response = $this->getJson('/api/v1/patients');

        $response->assertOk();
        $items = collect($response->json('data.items'))->keyBy('id');
        $this->assertSame('3529010101800001', $items[$sumenep->id]['nik']);
        $this->assertSame('Tidak Diketahui', $items[$luarKode->id]['nik']);
    }

    public function test_filter_wilayah_status(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $resolved = $this->makePatient($this->puskesmasA, 1, ['wilayah_status' => 'resolved']);
        $this->makePatient($this->puskesmasA, 2, ['wilayah_status' => 'unknown']);

        Sanctum::actingAs($superAdmin);

        $response = $this->getJson('/api/v1/patients?wilayah_status=resolved');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertEquals([$resolved->id], $ids->all());
    }

    public function test_filter_risk_level_pakai_klasifikasi_terbaru(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $berat = $this->makePatient($this->puskesmasA, 1);
        $ringan = $this->makePatient($this->puskesmasA, 2);

        RiskClassification::create(['patient_id' => $berat->id, 'level' => 'berat', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);
        RiskClassification::create(['patient_id' => $ringan->id, 'level' => 'ringan', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        Sanctum::actingAs($superAdmin);

        $response = $this->getJson('/api/v1/patients?risk_level=berat');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertEquals([$berat->id], $ids->all());
        $this->assertSame('berat', $response->json('data.items.0.risk_level'));
    }

    public function test_filter_risk_level_tidak_valid_ditolak_422(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        Sanctum::actingAs($superAdmin);

        $response = $this->getJson('/api/v1/patients?risk_level=parah-sekali');

        $response->assertStatus(422);
        $this->assertSame('error', $response->json('status'));
    }

    // ---- Revisi Bu Kadis (Fase 5): filter puskesmas_id ----

    public function test_super_admin_boleh_filter_puskesmas_id(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $patientA = $this->makePatient($this->puskesmasA, 1);
        $this->makePatient($this->puskesmasB, 2);

        Sanctum::actingAs($superAdmin);

        $response = $this->getJson("/api/v1/patients?puskesmas_id={$this->puskesmasA->id}");

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertEquals([$patientA->id], $ids->all());
    }

    public function test_admin_puskesmas_filter_puskesmas_id_diabaikan_tetap_terkunci_sendiri(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $patientA = $this->makePatient($this->puskesmasA, 1);
        $this->makePatient($this->puskesmasB, 2);

        Sanctum::actingAs($admin);

        // Coba filter ke puskesmas LAIN -- harus tetap cuma lihat puskesmasnya sendiri, bukan 403
        // ataupun bocor ke puskesmas B.
        $response = $this->getJson("/api/v1/patients?puskesmas_id={$this->puskesmasB->id}");

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertEquals([$patientA->id], $ids->all());
    }

    // ---- Revisi Bu Kadis (Fase 5): ekspor PDF ----

    public function test_export_pdf_menghasilkan_file_pdf(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $this->makePatient($this->puskesmasA, 1);
        $this->makePatient($this->puskesmasB, 2);

        Sanctum::actingAs($superAdmin);

        $response = $this->get('/api/v1/patients/export-pdf');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_export_pdf_menghormati_filter_yang_sama_dengan_index(): void
    {
        // PDF dompdf terkompresi (FlateDecode) -- tidak bisa dicek isi teksnya via string search
        // biasa, jadi diverifikasi tidak langsung: applyFilters()/scopedQuery() persis SAMA
        // dengan yang dipakai index() (sudah diverifikasi ketat di test scoping index() di atas),
        // sini cukup pastikan hasil admin_puskesmas (1 pasien dalam scope) menghasilkan PDF lebih
        // kecil dari super_admin (2 pasien) -- baris tabel yang lebih sedikit.
        $superAdmin = $this->makeUser('super_admin');
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $this->makePatient($this->puskesmasA, 1);
        $this->makePatient($this->puskesmasB, 2);

        Sanctum::actingAs($superAdmin);
        $fullResponse = $this->get('/api/v1/patients/export-pdf');
        $fullResponse->assertOk();

        Sanctum::actingAs($admin);
        $scopedResponse = $this->get('/api/v1/patients/export-pdf');
        $scopedResponse->assertOk();

        $this->assertLessThan(strlen($fullResponse->getContent()), strlen($scopedResponse->getContent()));
    }

    public function test_export_pdf_nama_file_tanpa_filter_pakai_fallback(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $this->makePatient($this->puskesmasA, 1);

        Sanctum::actingAs($superAdmin);

        $response = $this->get('/api/v1/patients/export-pdf');

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('data-pasien-prolanis-seluruh-wilayah-semua-risiko-', $disposition);
    }

    public function test_export_pdf_nama_file_mengikuti_filter_puskesmas_dan_risiko(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $this->makePatient($this->puskesmasA, 1);

        Sanctum::actingAs($superAdmin);

        $response = $this->get("/api/v1/patients/export-pdf?puskesmas_id={$this->puskesmasA->id}&risk_level=sedang");

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('data-pasien-prolanis-puskesmas-a-risiko-sedang-', $disposition);
    }

    public function test_export_pdf_nama_file_mengikuti_filter_kecamatan_kalau_puskesmas_tidak_diisi(): void
    {
        $kecamatan = Kecamatan::create(['kabupaten_id' => $this->puskesmasA->kabupaten_id, 'kode_kemendagri' => 'K01', 'nama' => 'Kecamatan Manding']);
        $superAdmin = $this->makeUser('super_admin');
        $this->makePatient($this->puskesmasA, 1);

        Sanctum::actingAs($superAdmin);

        $response = $this->get("/api/v1/patients/export-pdf?kecamatan_id={$kecamatan->id}");

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('data-pasien-prolanis-kecamatan-kecamatan-manding-semua-risiko-', $disposition);
    }

    public function test_export_pdf_nama_file_puskesmas_id_diabaikan_untuk_admin_puskesmas(): void
    {
        // admin_puskesmas terkunci ke puskesmas sendiri (bukan lewat query param, lihat
        // applyFilters()) -- puskesmas_id yang dikirim di sini HARUS diabaikan juga di nama
        // file, jatuh ke fallback "seluruh-wilayah", bukan menyelundupkan nama puskesmas lain.
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $this->makePatient($this->puskesmasA, 1);

        Sanctum::actingAs($admin);

        $response = $this->get("/api/v1/patients/export-pdf?puskesmas_id={$this->puskesmasB->id}");

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('data-pasien-prolanis-seluruh-wilayah-semua-risiko-', $disposition);
    }

    /**
     * Regresi bug nyata: resolveExportFilterSummary() (subjudul kop laporan PDF) sempat
     * menghasilkan "Puskesmas Puskesmas A" -- puskesmas.nama SUDAH termasuk kata "Puskesmas "
     * sendiri (lihat PuskesmasSeeder), jadi menambah "Puskesmas " lagi di depannya jadi dobel.
     * Ketahuan lewat pemeriksaan manual isi PDF asli (pdftotext), bukan dari test lama --
     * exportPdfFilename() (dipakai duluan) punya bug SAMA persis, ikut diperbaiki+diuji ulang di
     * test_export_pdf_nama_file_mengikuti_filter_puskesmas_dan_risiko() di atas.
     *
     * Diuji lewat reflection ke method private -- konten PDF dompdf terkompresi (FlateDecode),
     * tidak bisa dicek isi teksnya via string search biasa (lihat komentar test lain di file
     * ini), jadi logikanya diuji langsung di sini, terpisah dari test_export_pdf_* yang cuma
     * memastikan response tetap 200/PDF valid.
     */
    public function test_resolve_export_filter_summary_kombinasi_puskesmas_dan_risiko(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $request = Request::create("/api/v1/patients/export-pdf?puskesmas_id={$this->puskesmasA->id}&risk_level=sedang", 'GET');
        $request->setUserResolver(fn () => $superAdmin);

        $summary = $this->resolveExportFilterSummary($request);

        $this->assertSame('Puskesmas A, dengan klasifikasi tingkat risiko Sedang', $summary);
    }

    public function test_resolve_export_filter_summary_kecamatan_saja(): void
    {
        $kecamatan = Kecamatan::create(['kabupaten_id' => $this->puskesmasA->kabupaten_id, 'kode_kemendagri' => 'K01', 'nama' => 'Manding']);
        $superAdmin = $this->makeUser('super_admin');
        $request = Request::create("/api/v1/patients/export-pdf?kecamatan_id={$kecamatan->id}", 'GET');
        $request->setUserResolver(fn () => $superAdmin);

        $summary = $this->resolveExportFilterSummary($request);

        $this->assertSame('Kecamatan Manding', $summary);
    }

    public function test_resolve_export_filter_summary_risiko_saja(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $request = Request::create('/api/v1/patients/export-pdf?risk_level=berat', 'GET');
        $request->setUserResolver(fn () => $superAdmin);

        $summary = $this->resolveExportFilterSummary($request);

        $this->assertSame('Dengan klasifikasi tingkat risiko Berat', $summary);
    }

    public function test_resolve_export_filter_summary_null_kalau_tidak_ada_filter(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $request = Request::create('/api/v1/patients/export-pdf', 'GET');
        $request->setUserResolver(fn () => $superAdmin);

        $summary = $this->resolveExportFilterSummary($request);

        $this->assertNull($summary);
    }

    public function test_resolve_export_filter_summary_puskesmas_diabaikan_untuk_admin_puskesmas(): void
    {
        // admin_puskesmas terkunci ke puskesmas sendiri (bukan lewat query param) -- sama gerbang
        // dengan applyFilters()/exportPdfFilename(), puskesmas_id yang dikirim di sini tidak boleh
        // ikut nongol di subjudul PDF.
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $request = Request::create("/api/v1/patients/export-pdf?puskesmas_id={$this->puskesmasB->id}", 'GET');
        $request->setUserResolver(fn () => $admin);

        $summary = $this->resolveExportFilterSummary($request);

        $this->assertNull($summary);
    }

    private function resolveExportFilterSummary(Request $request): ?string
    {
        $method = new \ReflectionMethod(PatientController::class, 'resolveExportFilterSummary');

        return $method->invoke(app(PatientController::class), $request);
    }

    public function test_export_pdf_puskesmas_id_tidak_ditemukan_ditolak_validasi(): void
    {
        // ListPatientsRequest sudah validasi exists:puskesmas,id -- ID yang tidak ada tidak
        // pernah sampai ke exportPdfFilename() sama sekali (fallback null-safe di sana tetap
        // dipertahankan sebagai jaga-jaga, tapi jalur ini yang benar-benar teruji).
        $superAdmin = $this->makeUser('super_admin');
        $this->makePatient($this->puskesmasA, 1);

        Sanctum::actingAs($superAdmin);

        $response = $this->getJson('/api/v1/patients/export-pdf?puskesmas_id=999999');

        $response->assertStatus(422);
    }

    /**
     * Regresi bug nyata: exportPdf() sebelumnya TIDAK membatasi jumlah baris sama sekali --
     * dompdf kehabisan memory_limit dan proses PHP mati mendadak (500 kosong, tanpa log) kalau
     * operator lupa mempersempit filter dulu (terbukti nyata dengan >1000 baris di data lokal).
     * Batas di-override ke 1 di sini supaya test tetap cepat (tidak perlu benar-benar bikin
     * ratusan baris) -- yang diuji adalah PERILAKU penolakannya, bukan angka batas asli.
     */
    public function test_export_pdf_ditolak_kalau_melebihi_batas_baris(): void
    {
        config(['produli.reports.pdf_export_max_rows' => 1]);

        $superAdmin = $this->makeUser('super_admin');
        $this->makePatient($this->puskesmasA, 1);
        $this->makePatient($this->puskesmasB, 2);

        Sanctum::actingAs($superAdmin);

        $response = $this->getJson('/api/v1/patients/export-pdf');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('filter');
    }

    public function test_export_pdf_tetap_berhasil_saat_persis_di_batas(): void
    {
        config(['produli.reports.pdf_export_max_rows' => 2]);

        $superAdmin = $this->makeUser('super_admin');
        $this->makePatient($this->puskesmasA, 1);
        $this->makePatient($this->puskesmasB, 2);

        Sanctum::actingAs($superAdmin);

        $response = $this->get('/api/v1/patients/export-pdf');

        $response->assertOk();
    }

    // ---- Revisi Bu Kadis (Fase 5): riwayat klasifikasi risiko & kunjungan ----

    public function test_risk_history_mengembalikan_semua_baris_bukan_cuma_latest(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $patient = $this->makePatient($this->puskesmasA, 1);

        RiskClassification::create(['patient_id' => $patient->id, 'level' => 'berat', 'criteria_snapshot' => [], 'computed_at' => now()->subDays(5), 'is_latest' => false]);
        RiskClassification::create(['patient_id' => $patient->id, 'level' => 'sedang', 'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/patients/{$patient->id}/risk-history");

        $response->assertOk();
        $levels = collect($response->json('data'))->pluck('level');
        $this->assertCount(2, $levels);
        $this->assertSame(['sedang', 'berat'], $levels->all());
    }

    public function test_risk_history_pasien_di_luar_scope_ditolak_403(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $patientB = $this->makePatient($this->puskesmasB, 2);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/patients/{$patientB->id}/risk-history");

        $response->assertStatus(403);
    }

    public function test_visit_history_mengembalikan_kunjungan_kader_dan_tenaga_kesehatan(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $patient = $this->makePatient($this->puskesmasA, 1);
        $kaderUser = User::factory()->create(['puskesmas_id' => $this->puskesmasA->id]);
        $kader = Kader::create(['user_id' => $kaderUser->id, 'puskesmas_id' => $this->puskesmasA->id, 'status_aktif' => true]);

        VisitAssignment::create([
            'patient_id' => $patient->id, 'kader_id' => $kader->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'pending', 'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/patients/{$patient->id}/visit-history");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($kader->id, $response->json('data.0.kader.id'));
    }

    public function test_visit_history_pasien_di_luar_scope_ditolak_403(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $patientB = $this->makePatient($this->puskesmasB, 2);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/patients/{$patientB->id}/visit-history");

        $response->assertStatus(403);
    }

    public function test_lab_results_mengembalikan_hasil_terbaru_per_parameter(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $patient = $this->makePatient($this->puskesmasA, 1);

        LabResultCache::create([
            'external_id' => 1, 'patient_id' => $patient->external_patient_id,
            'parameter' => 'Gula Darah Puasa', 'value' => '90', 'satuan' => 'mg/dL',
            'nilai_rujukan' => '70-110', 'class_hasil' => 'Normal', 'validation_status' => 'valid',
            'tanggal_periksa' => '2026-06-01', 'synced_at' => '2026-06-01 08:00:00',
        ]);
        // Retest lebih baru untuk parameter yang sama -- yang ini yang harus muncul (bukan 90).
        LabResultCache::create([
            'external_id' => 2, 'patient_id' => $patient->external_patient_id,
            'parameter' => 'Gula Darah Puasa', 'value' => '250', 'satuan' => 'mg/dL',
            'nilai_rujukan' => '70-110', 'class_hasil' => 'Tinggi', 'validation_status' => 'valid',
            'tanggal_periksa' => '2026-07-20', 'synced_at' => '2026-07-20 08:00:00',
        ]);
        LabResultCache::create([
            'external_id' => 3, 'patient_id' => $patient->external_patient_id,
            'parameter' => 'Cholesterol', 'value' => '180', 'satuan' => 'mg/dL',
            'nilai_rujukan' => '<200', 'class_hasil' => 'Normal', 'validation_status' => 'valid',
            'tanggal_periksa' => '2026-07-20', 'synced_at' => '2026-07-20 08:00:00',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/patients/{$patient->id}/lab-results");

        $response->assertOk();
        $data = collect($response->json('data'))->keyBy('parameter');
        $this->assertCount(2, $data);
        $this->assertSame('250', $data['Gula Darah Puasa']['value']);
        $this->assertSame('70-110', $data['Gula Darah Puasa']['nilai_rujukan']);
        $this->assertSame('mg/dL', $data['Gula Darah Puasa']['satuan']);
        $this->assertSame('180', $data['Cholesterol']['value']);
    }

    public function test_lab_results_pasien_di_luar_scope_ditolak_403(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $patientB = $this->makePatient($this->puskesmasB, 2);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/patients/{$patientB->id}/lab-results");

        $response->assertStatus(403);
    }

    public function test_show_pasien_di_luar_scope_ditolak_403(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $patientB = $this->makePatient($this->puskesmasB, 2);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/patients/{$patientB->id}");

        $response->assertStatus(403);
    }

    public function test_show_pasien_dalam_scope_berhasil(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $patientA = $this->makePatient($this->puskesmasA, 1);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/patients/{$patientA->id}");

        $response->assertOk();
        $this->assertSame($patientA->id, $response->json('data.id'));
        $this->assertSame('success', $response->json('status'));
    }

    public function test_tanpa_login_ditolak_401(): void
    {
        $response = $this->getJson('/api/v1/patients');

        $response->assertStatus(401);
    }

    /**
     * Regresi bug nyata: 404 dari SiLAKES (pasien belum/tidak dikenal di sana -- mis. pasien
     * simulasi sintetis di lingkungan dev, atau pasien asli yang belum pernah punya pengajuan
     * pembaruan sama sekali) sempat ikut dilempar sebagai error tak tertangani ke frontend
     * ("Panggilan SiLAKES .../pembaruan-lapangan gagal (HTTP 404): ..."). Seharusnya cuma
     * berarti "belum ada riwayat" -- 200 dengan data kosong, bukan error.
     */
    public function test_update_history_404_dari_silakes_dianggap_belum_ada_riwayat(): void
    {
        Http::fake(['*/pembaruan-lapangan' => Http::response(['status' => 'error', 'message' => 'Not Found'], 404)]);

        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $patient = $this->makePatient($this->puskesmasA, 900000001);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/patients/{$patient->id}/update-history");

        $response->assertOk();
        $this->assertSame('success', $response->json('status'));
        $this->assertSame([], $response->json('data'));
    }

    public function test_update_history_error_selain_404_tetap_dilempar(): void
    {
        Http::fake(['*/pembaruan-lapangan' => Http::response(['status' => 'error', 'message' => 'Server error'], 500)]);

        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $patient = $this->makePatient($this->puskesmasA, 2);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/patients/{$patient->id}/update-history");

        $response->assertStatus(500);
    }
}
