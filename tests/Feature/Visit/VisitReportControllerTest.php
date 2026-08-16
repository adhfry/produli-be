<?php

namespace Tests\Feature\Visit;

use App\Models\Kabupaten;
use App\Models\Kader;
use App\Models\PatientsCache;
use App\Models\Puskesmas;
use App\Models\TenagaKesehatan;
use App\Models\User;
use App\Models\VisitAssignment;
use App\Models\VisitAssignmentCompanion;
use App\Models\VisitReport;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresi untuk POST /api/v1/visit-reports (docs/planning/02 §3/§5) -- upload foto multipart,
 * validasi 7-layer (VisitValidationService) lalu simpan + push ke S3/MinIO (VisitReportService),
 * lewat jalur HTTP asli (bukan panggil service langsung seperti VisitReportServiceTest).
 */
class VisitReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private VisitAssignment $assignment;

    private Kader $kader;

    private Puskesmas $puskesmas;

    private PatientsCache $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
        Storage::fake('s3');

        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $puskesmas = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-A', 'nama' => 'Puskesmas A']);
        $this->puskesmas = $puskesmas;

        $kaderUser = User::factory()->create(['puskesmas_id' => $puskesmas->id, 'name' => 'Bu Siti']);
        $kaderUser->assignRole('kader');
        $this->kader = Kader::create(['user_id' => $kaderUser->id, 'puskesmas_id' => $puskesmas->id, 'status_aktif' => true]);

        $patient = PatientsCache::create([
            'external_patient_id' => 990001,
            'nik_hash' => 'HASH-990001',
            'nama' => 'Pasien Uji',
            'wilayah_status' => 'resolved',
            'puskesmas_id' => $puskesmas->id,
            'geo_status' => 'verified',
            'latitude' => -7.0123,
            'longitude' => 113.8456,
        ]);
        $this->patient = $patient;

        $this->assignment = VisitAssignment::create([
            'patient_id' => $patient->id,
            'kader_id' => $this->kader->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'pending',
            'priority' => 'sedang',
            'puskesmas_id_snapshot' => $puskesmas->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'assignment_id' => $this->assignment->id,
            'photo' => UploadedFile::fake()->image('kunjungan.jpg', 200, 200),
            'latitude' => -7.0123,
            'longitude' => 113.8456,
            'gps_accuracy_meters' => 10,
            'gps_captured_at' => now()->toIso8601String(),
            'captured_live' => '1',
            'is_offline' => '0',
            'kondisi' => 'Kondisi stabil.',
        ], $overrides);
    }

    public function test_kader_berhasil_submit_laporan_kunjungan(): void
    {
        Sanctum::actingAs($this->kader->user);

        $response = $this->post('/api/v1/visit-reports', $this->validPayload(), ['Accept' => 'application/json']);

        $response->assertCreated();
        $this->assertSame('success', $response->json('status'));

        $report = VisitReport::first();
        $this->assertNotNull($report);
        $this->assertSame('completed', $this->assignment->fresh()->status);
        Storage::disk('s3')->assertExists($report->photo_path);
    }

    public function test_kader_berhasil_submit_disertai_usulan_data_pasien(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->kader->user);

        $response = $this->post('/api/v1/visit-reports', $this->validPayload([
            'alamat' => 'Jl. Mawar No. 3',
            'golongan_darah' => 'O',
            'is_bpjs' => '1',
            'jenis_prolanis' => 'DM',
        ]), ['Accept' => 'application/json']);

        $response->assertCreated();

        $report = VisitReport::first();
        $this->assertSame([
            'alamat' => 'Jl. Mawar No. 3',
            'golongan_darah' => 'O',
            'is_bpjs' => true,
            'jenis_prolanis' => 'DM',
        ], $report->patient_field_updates);
    }

    public function test_kader_berhasil_submit_disertai_pemeriksaan_kunjungan(): void
    {
        Sanctum::actingAs($this->kader->user);

        $response = $this->post('/api/v1/visit-reports', $this->validPayload([
            'gda' => '180.5',
            'gdp' => '150',
            'gd2jpp' => '200',
            'uric_acid' => '7.2',
            'cholesterol' => '220',
            'systolic' => '140',
            'diastolic' => '90',
            'keluhan' => 'Pusing dan mudah lelah.',
            'tindakan' => ['diberi_obat'],
        ]), ['Accept' => 'application/json']);

        $response->assertCreated();

        $report = VisitReport::first();
        $this->assertEquals(180.5, (float) $report->gda);
        $this->assertEquals(150, (float) $report->gdp);
        $this->assertEquals(200, (float) $report->gd2jpp);
        $this->assertEquals(7.2, (float) $report->uric_acid);
        $this->assertEquals(220, (float) $report->cholesterol);
        $this->assertSame(140, $report->systolic);
        $this->assertSame(90, $report->diastolic);
        $this->assertSame('Pusing dan mudah lelah.', $report->keluhan);
        $this->assertSame(['diberi_obat'], $report->tindakan);

        // Ikut tampil di response resource, bukan cuma tersimpan di DB.
        $this->assertSame(140, $response->json('data.systolic'));
        $this->assertSame(['diberi_obat'], $response->json('data.tindakan'));
    }

    public function test_kader_bisa_pilih_lebih_dari_satu_tindakan_sekaligus(): void
    {
        Sanctum::actingAs($this->kader->user);

        $response = $this->post('/api/v1/visit-reports', $this->validPayload([
            'tindakan' => ['diberi_obat', 'dirujuk_puskesmas'],
            'cara_rujukan' => 'diantar_keluarga',
        ]), ['Accept' => 'application/json']);

        $response->assertCreated();
        $report = VisitReport::first();
        $this->assertSame(['diberi_obat', 'dirujuk_puskesmas'], $report->tindakan);
        $this->assertSame('diantar_keluarga', $report->cara_rujukan);
        $this->assertSame('menunggu_konfirmasi', $report->rujukan_status);
        $this->assertSame('menunggu_konfirmasi', $response->json('data.rujukan_status'));
    }

    public function test_cara_rujukan_wajib_kalau_tindakan_mencakup_dirujuk_puskesmas(): void
    {
        Sanctum::actingAs($this->kader->user);

        $response = $this->post('/api/v1/visit-reports', $this->validPayload([
            'tindakan' => ['dirujuk_puskesmas'],
            // cara_rujukan SENGAJA tidak dikirim.
        ]), ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('cara_rujukan');
        $this->assertSame(0, VisitReport::count());
    }

    // ---- Kunjungan berombongan: attendee (docs/planning/02 §16) ----

    private function makeCompanionKader(string $name = 'Bu Companion'): Kader
    {
        $user = User::factory()->create(['puskesmas_id' => $this->kader->puskesmas_id, 'name' => $name]);
        $user->assignRole('kader');

        return Kader::create(['user_id' => $user->id, 'puskesmas_id' => $this->kader->puskesmas_id, 'status_aktif' => true]);
    }

    public function test_attendee_tidak_dikirim_pre_fill_dari_companion_rencana(): void
    {
        $companion = $this->makeCompanionKader();
        VisitAssignmentCompanion::create(['assignment_id' => $this->assignment->id, 'kader_id' => $companion->id]);

        Sanctum::actingAs($this->kader->user);

        // attendee_kader_ids SENGAJA tidak dikirim sama sekali.
        $response = $this->post('/api/v1/visit-reports', $this->validPayload(), ['Accept' => 'application/json']);

        $response->assertCreated();
        $report = VisitReport::first();
        $this->assertSame([$companion->id], $report->attendees()->pluck('kader_id')->all());
        $this->assertSame($companion->id, $response->json('data.attendees.0.id'));
        $this->assertSame('Bu Companion', $response->json('data.attendees.0.nama'));
    }

    public function test_attendee_dikirim_kosong_eksplisit_override_pre_fill(): void
    {
        $companion = $this->makeCompanionKader();
        VisitAssignmentCompanion::create(['assignment_id' => $this->assignment->id, 'kader_id' => $companion->id]);

        Sanctum::actingAs($this->kader->user);

        // Kader primer mengoreksi: companion yang direncanakan ternyata batal ikut.
        $response = $this->post('/api/v1/visit-reports', $this->validPayload([
            'attendee_kader_ids' => [],
        ]), ['Accept' => 'application/json']);

        $response->assertCreated();
        $report = VisitReport::first();
        $this->assertSame([], $report->attendees()->pluck('kader_id')->all());
    }

    public function test_attendee_dikirim_eksplisit_tambahan_tak_terencana(): void
    {
        // Tidak ada companion RENCANA sama sekali, tapi kader primer tambahkan attendee
        // tak terencana saat submit laporan.
        $tambahan = $this->makeCompanionKader('Bu Tambahan');

        Sanctum::actingAs($this->kader->user);

        $response = $this->post('/api/v1/visit-reports', $this->validPayload([
            'attendee_kader_ids' => [$tambahan->id],
        ]), ['Accept' => 'application/json']);

        $response->assertCreated();
        $report = VisitReport::first();
        $this->assertSame([$tambahan->id], $report->attendees()->pluck('kader_id')->all());
    }

    public function test_kader_primer_tidak_dobel_tercatat_sebagai_attendee(): void
    {
        Sanctum::actingAs($this->kader->user);

        $response = $this->post('/api/v1/visit-reports', $this->validPayload([
            'attendee_kader_ids' => [$this->kader->id],
        ]), ['Accept' => 'application/json']);

        $response->assertCreated();
        $report = VisitReport::first();
        $this->assertSame([], $report->attendees()->pluck('kader_id')->all());
    }

    public function test_tindakan_ditolak_kalau_bukan_nilai_enum_yang_valid(): void
    {
        Sanctum::actingAs($this->kader->user);

        $response = $this->post('/api/v1/visit-reports', $this->validPayload([
            'tindakan' => ['dipulangkan_saja'],
        ]), ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $this->assertSame(0, VisitReport::count());
    }

    public function test_kader_tidak_bisa_submit_untuk_assignment_kader_lain(): void
    {
        $kaderLainUser = User::factory()->create();
        $kaderLainUser->assignRole('kader');
        Kader::create(['user_id' => $kaderLainUser->id, 'puskesmas_id' => $this->kader->puskesmas_id, 'status_aktif' => true]);

        Sanctum::actingAs($kaderLainUser);

        $response = $this->post('/api/v1/visit-reports', $this->validPayload(), ['Accept' => 'application/json']);

        $response->assertStatus(403);
        $this->assertSame(0, VisitReport::count());
    }

    public function test_admin_puskesmas_tanpa_profil_kader_tidak_bisa_submit(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin_puskesmas');

        Sanctum::actingAs($admin);

        $response = $this->post('/api/v1/visit-reports', $this->validPayload(), ['Accept' => 'application/json']);

        $response->assertStatus(403);
    }

    public function test_submit_gagal_validasi_geofence_lokasi_terlalu_jauh(): void
    {
        Sanctum::actingAs($this->kader->user);

        $response = $this->post('/api/v1/visit-reports', $this->validPayload([
            'latitude' => -8.5,
            'longitude' => 115.0,
        ]), ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $this->assertSame(0, VisitReport::count());
        $this->assertSame('pending', $this->assignment->fresh()->status);
    }

    public function test_foto_wajib_diisi(): void
    {
        Sanctum::actingAs($this->kader->user);

        $payload = $this->validPayload();
        unset($payload['photo']);

        $response = $this->post('/api/v1/visit-reports', $payload, ['Accept' => 'application/json']);

        $response->assertStatus(422);
    }

    // ---- Tenaga kesehatan submit laporan (revisi Bu Kadis PMO) ----

    public function test_tenaga_kesehatan_berhasil_submit_laporan_kunjungan(): void
    {
        $tkUser = User::factory()->create(['puskesmas_id' => $this->puskesmas->id, 'name' => 'Pak Budi']);
        $tkUser->assignRole('tenaga_kesehatan');
        $tenagaKesehatan = TenagaKesehatan::create(['user_id' => $tkUser->id, 'puskesmas_id' => $this->puskesmas->id, 'status_aktif' => true]);

        $tkAssignment = VisitAssignment::create([
            'patient_id' => $this->patient->id,
            'tenaga_kesehatan_id' => $tenagaKesehatan->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'pending',
            'priority' => 'sedang',
            'puskesmas_id_snapshot' => $this->puskesmas->id,
        ]);

        Sanctum::actingAs($tkUser);

        $response = $this->post('/api/v1/visit-reports', $this->validPayload([
            'assignment_id' => $tkAssignment->id,
            'gda' => '180',
            'systolic' => '130',
        ]), ['Accept' => 'application/json']);

        $response->assertCreated();
        $this->assertSame('completed', $tkAssignment->fresh()->status);
        $report = VisitReport::first();
        $this->assertEquals(180, (float) $report->gda);
        $this->assertSame(130, $report->systolic);
    }

    public function test_tenaga_kesehatan_tidak_bisa_submit_untuk_assignment_kader(): void
    {
        $tkUser = User::factory()->create(['puskesmas_id' => $this->puskesmas->id]);
        $tkUser->assignRole('tenaga_kesehatan');
        TenagaKesehatan::create(['user_id' => $tkUser->id, 'puskesmas_id' => $this->puskesmas->id, 'status_aktif' => true]);

        Sanctum::actingAs($tkUser);

        // $this->assignment adalah milik kader, bukan tenaga_kesehatan ini.
        $response = $this->post('/api/v1/visit-reports', $this->validPayload(), ['Accept' => 'application/json']);

        $response->assertStatus(403);
        $this->assertSame(0, VisitReport::count());
    }

    public function test_kader_berhasil_submit_laporan_pmo_kepatuhan_dan_sisa_obat(): void
    {
        Sanctum::actingAs($this->kader->user);

        $response = $this->post('/api/v1/visit-reports', $this->validPayload([
            'kepatuhan_obat' => 'kurang_patuh',
            'sisa_obat' => 'menipis',
        ]), ['Accept' => 'application/json']);

        $response->assertCreated();
        $report = VisitReport::first();
        $this->assertSame('kurang_patuh', $report->kepatuhan_obat);
        $this->assertSame('menipis', $report->sisa_obat);
        $this->assertSame('kurang_patuh', $response->json('data.kepatuhan_obat'));
    }

    public function test_tanpa_login_ditolak_401(): void
    {
        $this->post('/api/v1/visit-reports', $this->validPayload(), ['Accept' => 'application/json'])
            ->assertStatus(401);
    }
}
