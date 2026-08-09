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
