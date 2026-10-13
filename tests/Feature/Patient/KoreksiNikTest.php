<?php

namespace Tests\Feature\Patient;

use App\Http\Requests\Visit\SubmitVisitReportRequest;
use App\Jobs\SyncPatientFieldUpdateToSilakesJob;
use App\Models\Kabupaten;
use App\Models\Kader;
use App\Models\PatientsCache;
use App\Models\Puskesmas;
use App\Models\User;
use App\Models\VisitAssignment;
use App\Support\NikHasher;
use App\Support\NikKoreksi;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Koreksi NIK lewat usulan pembaruan data (permintaan Kepala Dinas 2026-10-13): hanya pasien yang
 * NIK-nya kosong, "-", atau berisi nomor registrasi.
 */
class KoreksiNikTest extends TestCase
{
    use RefreshDatabase;

    private Puskesmas $puskesmas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $this->puskesmas = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-NIK', 'nama' => 'Puskesmas NIK']);
    }

    private function staf(): User
    {
        $user = User::factory()->create(['puskesmas_id' => $this->puskesmas->id]);
        $user->assignRole('admin_puskesmas');

        return $user;
    }

    private function pasien(int $id, array $overrides = []): PatientsCache
    {
        return PatientsCache::create(array_merge([
            'external_patient_id' => $id,
            'nik_hash' => 'HASH-'.$id,
            'nama' => 'Pasien '.$id,
            'no_reg' => 'REG-'.$id,
            'puskesmas_id' => $this->puskesmas->id,
            'wilayah_status' => 'unknown',
        ], $overrides));
    }

    public function test_aturan_kelayakan_nik(): void
    {
        $this->assertTrue(NikKoreksi::bisaDiajukan(null, 'REG-1'));
        $this->assertTrue(NikKoreksi::bisaDiajukan('', 'REG-1'));
        $this->assertTrue(NikKoreksi::bisaDiajukan('-', 'REG-1'));
        $this->assertTrue(NikKoreksi::bisaDiajukan(' -- ', null));
        $this->assertTrue(NikKoreksi::bisaDiajukan('REG-20260101-0007', 'REG-20260101-0007'));
        $this->assertTrue(NikKoreksi::bisaDiajukan('NOREG reg-77', 'REG-77'));
        $this->assertFalse(NikKoreksi::bisaDiajukan('3529010101800001', 'REG-1'));
        $this->assertFalse(NikKoreksi::bisaDiajukan('1234567890123456', 'REG-1'));
        $this->assertFalse(NikKoreksi::bisaDiajukan('REG-9', null));
    }

    public function test_resource_pasien_menandai_nik_yang_bisa_dikoreksi_tanpa_membocorkan_nik(): void
    {
        Sanctum::actingAs($this->staf());
        $placeholder = $this->pasien(1, ['nik' => 'REG-1']);
        $valid = $this->pasien(2, ['nik' => '3529010101800002']);

        $this->getJson("/api/v1/patients/{$placeholder->id}")
            ->assertOk()
            ->assertJsonPath('data.nik_bisa_dikoreksi', true)
            ->assertJsonPath('data.nik', 'Tidak Diketahui');
        $this->getJson("/api/v1/patients/{$valid->id}")->assertJsonPath('data.nik_bisa_dikoreksi', false);
    }

    public function test_staf_bisa_mengajukan_nik_untuk_pasien_ber_nik_placeholder(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->staf());
        $pasien = $this->pasien(3, ['nik' => '-']);

        $this->patchJson("/api/v1/patients/{$pasien->id}/propose-update", ['nik' => '3529010101800003'])
            ->assertOk();

        Queue::assertPushed(SyncPatientFieldUpdateToSilakesJob::class, fn ($job) => $job->fields === ['nik' => '3529010101800003']);
    }

    public function test_nik_pasien_yang_sudah_terisi_tidak_bisa_diganti_dari_produli(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->staf());
        $pasien = $this->pasien(4, ['nik' => '3529010101800004']);

        $this->patchJson("/api/v1/patients/{$pasien->id}/propose-update", ['nik' => '3529010101800099'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nik');

        Queue::assertNothingPushed();
    }

    public function test_nik_yang_sudah_dipakai_pasien_lain_atau_format_salah_ditolak(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->staf());
        $this->pasien(5, ['nik' => '3529010101800005', 'nik_hash' => NikHasher::hash('3529010101800005')]);
        $pasien = $this->pasien(6, ['nik' => 'REG-6']);

        $this->patchJson("/api/v1/patients/{$pasien->id}/propose-update", ['nik' => '3529010101800005'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nik');
        $this->patchJson("/api/v1/patients/{$pasien->id}/propose-update", ['nik' => '35290101'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nik');

        Queue::assertNothingPushed();
    }

    public function test_kader_nik_layak_ikut_usulan_nik_tidak_layak_dibuang_tanpa_menggagalkan_laporan(): void
    {
        $kaderUser = User::factory()->create(['puskesmas_id' => $this->puskesmas->id]);
        $kaderUser->assignRole('kader');
        $kader = Kader::create(['user_id' => $kaderUser->id, 'puskesmas_id' => $this->puskesmas->id, 'status_aktif' => true]);
        $tugas = fn (PatientsCache $p) => VisitAssignment::create([
            'patient_id' => $p->id, 'kader_id' => $kader->id, 'scheduled_date' => now()->toDateString(),
            'status' => 'pending', 'priority' => 'sedang', 'puskesmas_id_snapshot' => $this->puskesmas->id,
        ]);

        $usulan = function (VisitAssignment $assignment, array $data): array {
            $request = SubmitVisitReportRequest::create('/api/v1/visit-reports', 'POST', ['assignment_id' => $assignment->id] + $data);
            $rules = Arr::only($request->rules(), ['assignment_id', 'phone', 'nik']);
            $request->setValidator(Validator::make($request->all(), $rules));

            return $request->patientFieldUpdates();
        };

        $layak = $tugas($this->pasien(7, ['nik' => 'REG-7']));
        $this->assertSame(['phone' => '081234', 'nik' => '3529010101800007'], $usulan($layak, ['phone' => '081234', 'nik' => '3529010101800007']));

        $sudahValid = $tugas($this->pasien(8, ['nik' => '3529010101800008']));
        $this->assertSame(['phone' => '081234'], $usulan($sudahValid, ['phone' => '081234', 'nik' => '3529010101800098']));

        $formatSalah = $tugas($this->pasien(9, ['nik' => '-']));
        $this->assertSame(['phone' => '081234'], $usulan($formatSalah, ['phone' => '081234', 'nik' => '12345']));
    }
}
