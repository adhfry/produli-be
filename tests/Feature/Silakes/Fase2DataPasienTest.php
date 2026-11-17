<?php

namespace Tests\Feature\Silakes;

use App\Models\Desa;
use App\Models\IntegrationSyncLog;
use App\Models\Kabupaten;
use App\Models\Kecamatan;
use App\Models\PatientsCache;
use App\Models\PatientSyncHistory;
use App\Models\Puskesmas;
use App\Models\User;
use App\Services\Notification\NotifyService;
use App\Services\Realtime\RealtimeBroadcastService;
use App\Services\Silakes\SilakesEventService;
use App\Services\Silakes\SyncSilakesService;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Fase 2 blueprint docs/planning/16 (2026-11-17): data pasien lengkap, riwayat sinkron, kunci
 * kepemilikan puskesmas, semua pasien Prolanis, peringatan sinkron gagal, event patient.merged.
 */
class Fase2DataPasienTest extends TestCase
{
    use RefreshDatabase;

    private Puskesmas $pkmA;

    private Puskesmas $pkmB;

    /** @var \Mockery\MockInterface */
    private $notify;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->seed(RolesSeeder::class);

        $kab = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $kec = Kecamatan::create(['kabupaten_id' => $kab->id, 'kode_kemendagri' => 'K28', 'nama' => 'Talango']);
        $this->pkmA = Puskesmas::create(['kabupaten_id' => $kab->id, 'kode_internal' => 'PKM-A', 'nama' => 'Puskesmas A']);
        $this->pkmB = Puskesmas::create(['kabupaten_id' => $kab->id, 'kode_internal' => 'PKM-B', 'nama' => 'Puskesmas B']);
        Desa::create(['kecamatan_id' => $kec->id, 'kode_kemendagri' => 'D-TALANGO', 'nama' => 'Talango', 'puskesmas_id' => $this->pkmB->id]);

        $this->notify = Mockery::spy(NotifyService::class);
        $this->app->instance(NotifyService::class, $this->notify);
        $this->app->instance(RealtimeBroadcastService::class, Mockery::spy(RealtimeBroadcastService::class));
    }

    private function baris(int $id, array $o = []): array
    {
        return array_merge([
            'patient_id' => $id, 'no_reg' => "REG-{$id}", 'name' => "Pasien {$id}", 'nik' => '3529010101800001', 'nik_hash' => "H-{$id}",
            'gender' => 'L', 'tgl_lahir' => '1980-01-01', 'phone' => '081200000001', 'alamat' => 'Jl KTP', 'rt_rw' => '001/002',
            'kel_desa' => 'Talango', 'kecamatan' => 'Talango', 'is_prolanis' => true, 'jenis_prolanis' => 'DM',
            'is_perokok' => false, 'jenis_perokok' => null, 'no_bpjs' => null,
            'tempat_lahir' => 'Sumenep', 'golongan_darah' => 'O', 'agama' => 'ISLAM', 'status_perkawinan' => 'KAWIN',
            'pekerjaan' => 'Petani', 'is_bpjs' => false, 'fktp' => 'Puskesmas B', 'email' => null,
            'domisili' => ['alamat' => 'Dusun Domisili', 'rt_rw' => '003/004', 'kel_desa' => 'Talango', 'kecamatan' => 'Talango',
                'latitude' => -7.0, 'longitude' => 113.9, 'sumber' => 'produli_kunjungan', 'updated_at' => '2026-11-01T08:00:00+07:00'],
            'updated_at' => '2026-11-10T08:00:00+07:00',
        ], $o);
    }

    private function sync(): SyncSilakesService
    {
        return app(SyncSilakesService::class);
    }

    private function fakeSemua(array $pasien): void
    {
        $kosong = ['status' => 'success', 'data' => [], 'meta' => ['has_more' => false, 'next_cursor' => null]];
        Http::fake([
            '*/api/v1/integration/patients*' => Http::response(['status' => 'success', 'data' => $pasien, 'meta' => ['has_more' => false, 'next_cursor' => null]]),
            '*/api/v1/integration/lab-results*' => Http::response($kosong),
            '*/api/v1/integration/reference-ranges*' => Http::response(['status' => 'success', 'data' => ['aliases' => [], 'ranges' => []]]),
        ]);
    }

    // ------------------------------------------------------------------ pemetaan & riwayat

    public function test_kolom_lengkap_dan_domisili_dipetakan_payload_lama_tidak_mengosongkan(): void
    {
        $p = $this->sync()->upsertPatient($this->baris(5001), 'harian');
        $this->assertSame('Sumenep', $p->tempat_lahir);
        $this->assertSame('Petani', $p->pekerjaan);
        $this->assertSame('Dusun Domisili', $p->domisili_alamat);
        $this->assertSame('harian', $p->refreshed_via);
        $this->assertNotNull($p->refreshed_at);

        $lama = $this->baris(5001);
        foreach (['tempat_lahir', 'golongan_darah', 'agama', 'status_perkawinan', 'pekerjaan', 'is_bpjs', 'fktp', 'email', 'domisili'] as $k) {
            unset($lama[$k]);
        }
        $this->sync()->upsertPatient($lama, 'harian');
        $this->assertSame('Petani', $p->fresh()->pekerjaan, 'payload SiLAKES lama tidak mengosongkan kolom baru');
        $this->assertSame('Dusun Domisili', $p->fresh()->domisili_alamat);
    }

    public function test_riwayat_hanya_untuk_perubahan_nyata_bukan_isian_awal(): void
    {
        // Pasien lama (sebelum Fase 2): refreshed_at kosong -> tarikan pertama = data awal.
        $p = PatientsCache::create(['external_patient_id' => 5002, 'nik_hash' => 'H-5002', 'nama' => 'Pasien 5002', 'wilayah_status' => 'unknown']);
        $this->sync()->upsertPatient($this->baris(5002), 'harian');
        $this->assertSame(0, PatientSyncHistory::count(), 'isian awal kolom baru bukan perubahan');

        $this->sync()->upsertPatient($this->baris(5002), 'harian');
        $this->assertSame(0, PatientSyncHistory::count(), 'tidak ada yang berubah, tidak ada baris');

        $this->sync()->upsertPatient($this->baris(5002, ['phone' => '081299990000', 'pekerjaan' => 'Guru']), 'realtime', 'patient_field_update.approved');
        $h = PatientSyncHistory::where('patients_cache_id', $p->id)->sole();
        $this->assertSame('realtime', $h->sumber);
        $this->assertSame('patient_field_update.approved', $h->silakes_event);
        $this->assertSame(['lama' => '081200000001', 'baru' => '081299990000'], $h->changed_fields['phone']);
        $this->assertSame(['lama' => 'Petani', 'baru' => 'Guru'], $h->changed_fields['pekerjaan']);
    }

    // ------------------------------------------------------------------ kunci kepemilikan

    public function test_puskesmas_terkunci_tidak_dihitung_ulang_sinkron_maupun_reresolve(): void
    {
        $p = PatientsCache::create(['external_patient_id' => 5003, 'nik_hash' => 'H-5003', 'nama' => 'Pasien 5003', 'wilayah_status' => 'unknown',
            'puskesmas_id' => $this->pkmA->id, 'puskesmas_resolution_method' => 'kecamatan_fallback', 'puskesmas_locked_at' => now()]);

        $this->sync()->upsertPatient($this->baris(5003), 'harian'); // desa Talango -> Puskesmas B bila tidak terkunci
        $this->assertSame($this->pkmA->id, $p->fresh()->puskesmas_id);
        $this->assertSame('kecamatan_fallback', $p->fresh()->puskesmas_resolution_method, 'cara penetapan asli tetap untuk filter tinjau');

        $this->artisan('produli:reresolve-wilayah')->assertSuccessful();
        $this->assertSame($this->pkmA->id, $p->fresh()->puskesmas_id);
        $this->assertNotNull($p->fresh()->desa_id, 'wilayah tetap diperbarui');
    }

    public function test_pasien_baru_dikunci_saat_pertama_mendapat_puskesmas_yang_tak_terpetakan_tidak(): void
    {
        $dapat = $this->sync()->upsertPatient($this->baris(5004), 'harian');
        $this->assertSame($this->pkmB->id, $dapat->puskesmas_id);
        $this->assertNotNull($dapat->puskesmas_locked_at);

        $belum = $this->sync()->upsertPatient($this->baris(5005, ['kel_desa' => 'Entah', 'kecamatan' => 'SURABAYA']), 'harian');
        $this->assertNull($belum->puskesmas_id);
        $this->assertNull($belum->puskesmas_locked_at, 'yang belum dapat puskesmas tetap dicoba lagi tiap sinkron');
    }

    public function test_override_mengunci_ulang_dan_tercatat_di_riwayat(): void
    {
        $p = $this->sync()->upsertPatient($this->baris(5006), 'harian');
        $admin = User::factory()->create(['name' => 'Super Admin']);
        $admin->assignRole('super_admin');
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/patients/{$p->id}/override-puskesmas", ['puskesmas_id' => $this->pkmA->id, 'reason' => 'Pindah domisili'])->assertOk();

        $this->assertSame($this->pkmA->id, $p->fresh()->puskesmas_id);
        $h = PatientSyncHistory::where('patients_cache_id', $p->id)->sole();
        $this->assertSame('override', $h->sumber);
        $this->assertSame($this->pkmB->id, $h->changed_fields['puskesmas_id']['lama']);
        $this->assertSame('Pindah domisili', $h->changed_fields['puskesmas_id']['alasan']);

        $this->getJson("/api/v1/patients/{$p->id}/sync-history")->assertOk()
            ->assertJsonPath('data.items.0.sumber', 'override')
            ->assertJsonPath('data.refreshed_via', 'harian');
    }

    // ------------------------------------------------------------------ semua pasien Prolanis

    public function test_pasien_tanpa_hasil_lab_ikut_ditarik_dan_bisa_difilter(): void
    {
        $this->fakeSemua([$this->baris(5007)]);
        $this->sync()->runAndLog();

        $p = PatientsCache::where('external_patient_id', 5007)->firstOrFail();
        $this->assertSame('harian', $p->refreshed_via);

        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        Sanctum::actingAs($admin);
        $ids = collect($this->getJson('/api/v1/patients?belum_ada_hasil_lab=1&per_page=50')->assertOk()->json('data.items'))->pluck('id');
        $this->assertContains($p->id, $ids->all());
    }

    public function test_tarik_semua_uji_tidak_menulis_dan_tidak_menggeser_batas_sinkron(): void
    {
        IntegrationSyncLog::create(['service_name' => 'SyncSilakesService', 'endpoint' => 'patients+lab-results', 'requested_at' => now(),
            'status' => 'success', 'records_count' => 0, 'details' => ['patients_watermark' => '2026-11-17T02:00:00+07:00']]);
        $this->fakeSemua([$this->baris(5008), $this->baris(5009, ['updated_at' => '2026-11-17T10:00:00+07:00'])]);

        $this->artisan('produli:tarik-semua-pasien --uji')->assertSuccessful()->expectsOutputToContain('"baru":2');
        $this->assertSame(0, PatientsCache::count());

        $this->artisan('produli:tarik-semua-pasien')->assertSuccessful();
        $this->assertSame(2, PatientsCache::count());
        $this->assertSame('manual', PatientsCache::where('external_patient_id', 5009)->value('refreshed_via'));
        $this->assertSame('2026-11-17T02:00:00+07:00', $this->sync()->patientsSince(), 'batas sinkron harian tidak bergeser');
    }

    public function test_sinkron_gagal_dikabarkan_ke_super_admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'Unauthenticated.'], 401)]);

        try {
            $this->sync()->runAndLog();
        } catch (\Throwable) {
        }

        $this->notify->shouldHaveReceived('notify')->withArgs(fn ($target, $payload) => $payload->type === 'silakes_sync_failed'
            && $target->user?->id === $admin->id && str_contains($payload->body, 'gagal'));
        $this->assertSame('failed', IntegrationSyncLog::latest('id')->value('status'));
    }

    // ------------------------------------------------------------------ pasien digabung

    public function test_gabung_pasien_lama_dipindah_ke_tujuan_riwayat_tetap_menempel(): void
    {
        $lama = $this->sync()->upsertPatient($this->baris(6001, ['name' => 'AINIYAH']), 'harian');
        DB::table('lab_results_cache')->insert(['patient_id' => 6001, 'external_id' => 990001, 'parameter' => 'Gula Darah Puasa',
            'value' => '120', 'tanggal_periksa' => '2026-07-01', 'synced_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        app(SilakesEventService::class)->terapkanGabung(['from_patient_id' => 6001, 'to_patient_id' => 2382,
            'patient' => $this->baris(2382, ['name' => 'AINIYAH', 'no_reg' => '2300725002608'])]);

        $baru = $lama->fresh();
        $this->assertSame(2382, (int) $baru->external_patient_id, 'baris yang sama, id PRODULI tetap');
        $this->assertSame('2300725002608', $baru->no_reg);
        $this->assertSame(1, DB::table('lab_results_cache')->where('patient_id', 2382)->count());
        $this->assertSame(0, DB::table('lab_results_cache')->where('patient_id', 6001)->count());
        $this->assertSame(1, PatientsCache::count());
        $this->assertSame('patient.merged', PatientSyncHistory::where('patients_cache_id', $lama->id)->first()->silakes_event);
    }

    public function test_gabung_keduanya_sudah_ada_tidak_diubah_super_admin_diminta_meninjau(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $a = $this->sync()->upsertPatient($this->baris(6002), 'harian');
        $b = $this->sync()->upsertPatient($this->baris(6003), 'harian');

        app(SilakesEventService::class)->terapkanGabung(['from_patient_id' => 6002, 'to_patient_id' => 6003, 'patient' => $this->baris(6003)]);

        $this->assertSame(6002, (int) $a->fresh()->external_patient_id);
        $this->assertSame(6003, (int) $b->fresh()->external_patient_id);
        $this->notify->shouldHaveReceived('notify')->withArgs(fn ($target, $payload) => $payload->type === 'silakes_perlu_tinjau');
    }

    public function test_perintah_pangkas_pasien_tanpa_lab_dinonaktifkan(): void
    {
        $p = PatientsCache::create(['external_patient_id' => 7001, 'nik_hash' => 'H-7001', 'nama' => 'Tanpa Lab', 'wilayah_status' => 'unknown']);

        $this->artisan('produli:prune-ineligible-patients')->assertFailed();
        $this->assertNotNull($p->fresh());
    }
}
