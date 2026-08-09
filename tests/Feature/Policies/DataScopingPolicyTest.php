<?php

namespace Tests\Feature\Policies;

use App\Models\Kabupaten;
use App\Models\Kader;
use App\Models\LabResultCache;
use App\Models\PatientsCache;
use App\Models\Puskesmas;
use App\Models\RiskClassification;
use App\Models\User;
use App\Models\VisitAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataScopingPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Puskesmas $puskesmasA;

    private Puskesmas $puskesmasB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesSeeder::class);

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

    public function test_super_admin_bisa_lihat_pasien_di_puskesmas_manapun(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $patientA = $this->makePatient($this->puskesmasA, 1);
        $patientB = $this->makePatient($this->puskesmasB, 2);

        $this->assertTrue($superAdmin->can('view', $patientA));
        $this->assertTrue($superAdmin->can('view', $patientB));
    }

    public function test_admin_puskesmas_hanya_bisa_lihat_pasien_di_puskesmas_sendiri(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $patientA = $this->makePatient($this->puskesmasA, 1);
        $patientB = $this->makePatient($this->puskesmasB, 2);

        $this->assertTrue($admin->can('view', $patientA));
        $this->assertFalse($admin->can('view', $patientB));
    }

    public function test_pj_prolanis_scoping_sama_seperti_admin_puskesmas(): void
    {
        $pj = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $patientA = $this->makePatient($this->puskesmasA, 1);
        $patientB = $this->makePatient($this->puskesmasB, 2);

        $this->assertTrue($pj->can('view', $patientA));
        $this->assertFalse($pj->can('view', $patientB));
    }

    public function test_kader_murni_hanya_bisa_lihat_pasien_yang_punya_assignment_dengannya(): void
    {
        // Gap yang tadinya diketahui (Prompt 7) sudah ditutup: kader murni TIDAK lagi di-scope
        // selevel puskesmas (itu terlalu longgar), tapi selevel visit_assignments miliknya sendiri.
        $kaderUser = $this->makeUser('kader', $this->puskesmasA);
        $kader = Kader::create(['user_id' => $kaderUser->id, 'puskesmas_id' => $this->puskesmasA->id, 'status_aktif' => true]);

        $patientDitugaskan = $this->makePatient($this->puskesmasA, 1);
        $patientSamaPuskesmasTapiTidakDitugaskan = $this->makePatient($this->puskesmasA, 2);
        $patientPuskesmasLain = $this->makePatient($this->puskesmasB, 3);

        VisitAssignment::create([
            'patient_id' => $patientDitugaskan->id,
            'kader_id' => $kader->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'pending',
            'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmasA->id,
        ]);

        $this->assertTrue($kaderUser->can('view', $patientDitugaskan));
        $this->assertFalse($kaderUser->can('view', $patientSamaPuskesmasTapiTidakDitugaskan));
        $this->assertFalse($kaderUser->can('view', $patientPuskesmasLain));
    }

    public function test_kader_tanpa_profil_kader_ditolak_semua(): void
    {
        // User punya role 'kader' tapi belum ada baris di tabel kader (data setup belum lengkap).
        $kaderUser = $this->makeUser('kader', $this->puskesmasA);
        $patientA = $this->makePatient($this->puskesmasA, 1);

        $this->assertFalse($kaderUser->can('view', $patientA));
    }

    public function test_pj_prolanis_dual_role_kader_tetap_dapat_scope_puskesmas_penuh(): void
    {
        // "PJ bisa merangkap kader" (docs/planning/02 §7) — peran yang lebih luas (pj_prolanis)
        // menang, TIDAK ikut dibatasi ke assignment pribadi seperti kader murni.
        $user = $this->makeUser('pj_prolanis', $this->puskesmasA);
        $user->assignRole('kader');
        Kader::create(['user_id' => $user->id, 'puskesmas_id' => $this->puskesmasA->id, 'status_aktif' => true]);

        $patientTanpaAssignment = $this->makePatient($this->puskesmasA, 1);

        $this->assertTrue($user->hasRole('pj_prolanis'));
        $this->assertTrue($user->hasRole('kader'));
        $this->assertTrue($user->can('view', $patientTanpaAssignment));
    }

    public function test_user_tanpa_puskesmas_id_ditolak_meski_role_staf(): void
    {
        // admin_puskesmas yang belum di-assign ke puskesmas mana pun (data setup belum lengkap)
        // TIDAK BOLEH otomatis lolos — role saja tidak cukup (docs/planning/02 §7).
        $admin = $this->makeUser('admin_puskesmas', null);
        $patientA = $this->makePatient($this->puskesmasA, 1);

        $this->assertFalse($admin->can('view', $patientA));
    }

    public function test_lab_result_cache_scoping_ikut_puskesmas_pasien_terkait(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $patientA = $this->makePatient($this->puskesmasA, 1);
        $patientB = $this->makePatient($this->puskesmasB, 2);

        $labA = LabResultCache::create([
            'external_id' => 100, 'patient_id' => $patientA->external_patient_id,
            'parameter' => 'GDP', 'value' => '95',
            'tanggal_periksa' => now(), 'synced_at' => now(),
        ]);
        $labB = LabResultCache::create([
            'external_id' => 101, 'patient_id' => $patientB->external_patient_id,
            'parameter' => 'GDP', 'value' => '95',
            'tanggal_periksa' => now(), 'synced_at' => now(),
        ]);

        $this->assertTrue($admin->can('view', $labA));
        $this->assertFalse($admin->can('view', $labB));
    }

    public function test_risk_classification_scoping_ikut_puskesmas_pasien_terkait(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);
        $patientA = $this->makePatient($this->puskesmasA, 1);
        $patientB = $this->makePatient($this->puskesmasB, 2);

        $riskA = RiskClassification::create([
            'patient_id' => $patientA->id, 'level' => 'berat',
            'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true,
        ]);
        $riskB = RiskClassification::create([
            'patient_id' => $patientB->id, 'level' => 'berat',
            'criteria_snapshot' => [], 'computed_at' => now(), 'is_latest' => true,
        ]);

        $this->assertTrue($admin->can('view', $riskA));
        $this->assertFalse($admin->can('view', $riskB));
    }

    public function test_puskesmas_policy_view_terbuka_lintas_puskesmas_tapi_update_dibatasi(): void
    {
        $admin = $this->makeUser('admin_puskesmas', $this->puskesmasA);

        $this->assertTrue($admin->can('view', $this->puskesmasA));
        $this->assertTrue($admin->can('view', $this->puskesmasB));
        $this->assertTrue($admin->can('update', $this->puskesmasA));
        $this->assertFalse($admin->can('update', $this->puskesmasB));
    }
}
