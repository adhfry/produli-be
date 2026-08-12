<?php

namespace Tests\Feature\Patient;

use App\Models\Kabupaten;
use App\Models\Kader;
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
}
