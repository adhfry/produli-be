<?php

namespace Tests\Feature\Visit;

use App\Models\Kabupaten;
use App\Models\Kader;
use App\Models\PatientsCache;
use App\Models\Puskesmas;
use App\Models\RiskClassification;
use App\Models\User;
use App\Models\VisitAssignment;
use App\Services\Visit\VisitAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VisitAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private VisitAssignmentService $service;

    private Puskesmas $puskesmasA;

    private Puskesmas $puskesmasB;

    private Kader $kaderA;

    private User $assignedBy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(VisitAssignmentService::class);

        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $this->puskesmasA = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-A', 'nama' => 'Puskesmas A']);
        $this->puskesmasB = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-B', 'nama' => 'Puskesmas B']);

        $kaderUser = User::factory()->create();
        $this->kaderA = Kader::create(['user_id' => $kaderUser->id, 'puskesmas_id' => $this->puskesmasA->id, 'status_aktif' => true]);

        $this->assignedBy = User::factory()->create();
    }

    private function makePatient(array $overrides = []): PatientsCache
    {
        static $externalId = 0;
        $externalId++;

        return PatientsCache::create(array_merge([
            'external_patient_id' => 900000 + $externalId,
            'nik_hash' => 'HASH-'.$externalId,
            'nama' => 'Pasien '.$externalId,
            'wilayah_status' => 'unknown',
        ], $overrides));
    }

    public function test_assignment_berhasil_untuk_pasien_resolved(): void
    {
        $patient = $this->makePatient(['wilayah_status' => 'resolved', 'puskesmas_id' => $this->puskesmasA->id]);

        $assignment = $this->service->assign($patient, $this->kaderA, $this->assignedBy, '2026-08-10', 'sedang');

        $this->assertInstanceOf(VisitAssignment::class, $assignment);
        $this->assertSame('pending', $assignment->status);
        $this->assertSame($this->puskesmasA->id, $assignment->puskesmas_id_snapshot);
        $this->assertSame('wilayah_resolved', $assignment->assignment_method);
    }

    // ---- Pengecualian phone_contact (pasien Berat tanpa wilayah resolved, ada telepon) ----

    private function markBerat(PatientsCache $patient): void
    {
        RiskClassification::create([
            'patient_id' => $patient->id,
            'level' => 'berat',
            'criteria_snapshot' => [],
            'computed_at' => now(),
            'is_latest' => true,
        ]);
    }

    public function test_assignment_berhasil_via_phone_contact_untuk_pasien_berat_tanpa_wilayah_resolved(): void
    {
        $patient = $this->makePatient([
            'wilayah_status' => 'unknown',
            'puskesmas_resolution_method' => 'unresolvable',
            'puskesmas_id' => null,
            'phone' => '081234567890',
        ]);
        $this->markBerat($patient);

        $assignment = $this->service->assign($patient, $this->kaderA, $this->assignedBy, '2026-08-10', 'berat');

        $this->assertSame('phone_contact', $assignment->assignment_method);
        // Puskesmas pasien TIDAK diketahui -- snapshot dari puskesmas KADER, satu-satunya
        // nilai yang pasti valid di kasus ini (puskesmas_id_snapshot kolom NOT NULL).
        $this->assertSame($this->puskesmasA->id, $assignment->puskesmas_id_snapshot);
    }

    public function test_assignment_ditolak_pasien_berat_tanpa_wilayah_resolved_dan_tanpa_telepon(): void
    {
        $patient = $this->makePatient([
            'wilayah_status' => 'unknown',
            'puskesmas_id' => null,
            'phone' => null,
        ]);
        $this->markBerat($patient);

        $this->expectException(ValidationException::class);
        $this->service->assign($patient, $this->kaderA, $this->assignedBy, '2026-08-10', 'berat');
    }

    public function test_assignment_ditolak_pasien_sedang_tanpa_wilayah_resolved_meski_ada_telepon(): void
    {
        // Pengecualian phone_contact HANYA untuk risk_level=Berat -- Sedang/Ringan tetap wajib
        // wilayah resolved seperti biasa, tidak ikut dapat jalur alternatif ini.
        $patient = $this->makePatient([
            'wilayah_status' => 'unknown',
            'puskesmas_id' => null,
            'phone' => '081234567890',
        ]);
        RiskClassification::create([
            'patient_id' => $patient->id,
            'level' => 'sedang',
            'criteria_snapshot' => [],
            'computed_at' => now(),
            'is_latest' => true,
        ]);

        $this->expectException(ValidationException::class);
        $this->service->assign($patient, $this->kaderA, $this->assignedBy, '2026-08-10', 'sedang');
    }

    public function test_phone_contact_tidak_perlu_kader_sepuskesmas_dengan_pasien(): void
    {
        // patient->puskesmas_id null (lokasi tidak diketahui) -- perbandingan "kader sepuskesmas
        // dengan pasien" tidak mungkin dilakukan, jadi di-skip di jalur phone_contact.
        $patient = $this->makePatient([
            'wilayah_status' => 'unknown',
            'puskesmas_id' => null,
            'phone' => '081234567890',
        ]);
        $this->markBerat($patient);

        $assignment = $this->service->assign($patient, $this->kaderA, $this->assignedBy, '2026-08-10', 'berat');

        $this->assertSame('phone_contact', $assignment->assignment_method);
    }

    public function test_assignment_berhasil_untuk_pasien_kecamatan_fallback_meski_wilayah_status_bukan_resolved(): void
    {
        // Regresi: aturan diperluas dari "hanya resolved" jadi "resolved ATAU kecamatan_fallback"
        // (lihat riwayat percakapan) — pasien ini desa-nya TIDAK match tapi puskesmas tetap
        // ke-infer via kecamatan (1 puskesmas per kecamatan).
        $patient = $this->makePatient([
            'wilayah_status' => 'unresolved',
            'puskesmas_resolution_method' => 'kecamatan_fallback',
            'puskesmas_id' => $this->puskesmasA->id,
        ]);

        $assignment = $this->service->assign($patient, $this->kaderA, $this->assignedBy, '2026-08-10', 'ringan');

        $this->assertSame($this->puskesmasA->id, $assignment->puskesmas_id_snapshot);
    }

    public function test_assignment_ditolak_untuk_wilayah_status_unknown(): void
    {
        $patient = $this->makePatient(['wilayah_status' => 'unknown']);

        $this->expectException(ValidationException::class);
        $this->service->assign($patient, $this->kaderA, $this->assignedBy, '2026-08-10', 'sedang');
    }

    public function test_assignment_ditolak_untuk_wilayah_status_out_of_scope(): void
    {
        $patient = $this->makePatient(['wilayah_status' => 'out_of_scope']);

        $this->expectException(ValidationException::class);
        $this->service->assign($patient, $this->kaderA, $this->assignedBy, '2026-08-10', 'sedang');
    }

    public function test_assignment_ditolak_kalau_resolved_tapi_puskesmas_belum_ter_assign(): void
    {
        // Kasus nyata yang pernah ditemukan: desa match (wilayah_status=resolved) tapi
        // desa.puskesmas_id itu sendiri belum di-assign Dinkes -> puskesmas_id null.
        $patient = $this->makePatient(['wilayah_status' => 'resolved', 'puskesmas_id' => null]);

        $this->expectException(ValidationException::class);
        $this->service->assign($patient, $this->kaderA, $this->assignedBy, '2026-08-10', 'sedang');
    }

    public function test_assignment_ditolak_untuk_kader_tidak_aktif(): void
    {
        $this->kaderA->update(['status_aktif' => false]);
        $patient = $this->makePatient(['wilayah_status' => 'resolved', 'puskesmas_id' => $this->puskesmasA->id]);

        $this->expectException(ValidationException::class);
        $this->service->assign($patient, $this->kaderA, $this->assignedBy, '2026-08-10', 'sedang');
    }

    public function test_assignment_ditolak_untuk_kader_beda_puskesmas_dari_pasien(): void
    {
        $patient = $this->makePatient(['wilayah_status' => 'resolved', 'puskesmas_id' => $this->puskesmasB->id]);

        $this->expectException(ValidationException::class);
        $this->service->assign($patient, $this->kaderA, $this->assignedBy, '2026-08-10', 'sedang');
    }

    public function test_assignment_ditolak_kalau_pasien_sudah_punya_assignment_aktif_ke_kader_yang_sama(): void
    {
        $patient = $this->makePatient(['wilayah_status' => 'resolved', 'puskesmas_id' => $this->puskesmasA->id]);
        $this->service->assign($patient, $this->kaderA, $this->assignedBy, '2026-08-10', 'sedang');

        $this->expectException(ValidationException::class);
        $this->service->assign($patient, $this->kaderA, $this->assignedBy, '2026-08-11', 'sedang');
    }

    public function test_assignment_boleh_lagi_kalau_assignment_sebelumnya_sudah_completed(): void
    {
        $patient = $this->makePatient(['wilayah_status' => 'resolved', 'puskesmas_id' => $this->puskesmasA->id]);
        $first = $this->service->assign($patient, $this->kaderA, $this->assignedBy, '2026-08-10', 'sedang');
        $first->update(['status' => 'completed']);

        $second = $this->service->assign($patient, $this->kaderA, $this->assignedBy, '2026-08-17', 'sedang');

        $this->assertSame(2, VisitAssignment::where('patient_id', $patient->id)->count());
        $this->assertNotSame($first->id, $second->id);
    }
}
