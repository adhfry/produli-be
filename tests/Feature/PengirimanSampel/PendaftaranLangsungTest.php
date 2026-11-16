<?php

namespace Tests\Feature\PengirimanSampel;

use App\Jobs\RegisterPasienBaruToSilakesJob;
use App\Jobs\SendProlanisDeliveryToSilakesJob;
use App\Jobs\SyncPatientFieldUpdateToSilakesJob;
use App\Models\Kabupaten;
use App\Models\PatientsCache;
use App\Models\PengirimanSampel;
use App\Models\PengirimanSampelPasien;
use App\Models\Puskesmas;
use App\Models\SilakesFieldUpdateRef;
use App\Models\User;
use App\Services\Notification\NotifyService;
use App\Services\Realtime\RealtimeBroadcastService;
use App\Services\Silakes\SilakesApiClient;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Revisi 2026-11-17: pasien baru di antrian pengiriman sampel didaftarkan ke SiLAKES saat diisi,
 * keputusan Labkesda didorong balik (POST /integration/silakes-events) dan pengisinya dinotifikasi;
 * pencarian pasien sambil mengetik (nama/NIK/BPJS, maks 10).
 */
class PendaftaranLangsungTest extends TestCase
{
    use RefreshDatabase;

    private Puskesmas $puskesmas;

    private User $staf;

    /** @var \Mockery\MockInterface */
    private $notify;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
        Config::set('produli.silakes.write_token', 'test-write-token');
        Config::set('produli.silakes.signature_secret', 'rahasia-uji');

        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $this->puskesmas = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-BTN', 'nama' => 'Puskesmas Batuan']);
        $this->staf = User::factory()->create(['puskesmas_id' => $this->puskesmas->id, 'name' => 'Admin Batuan']);
        $this->staf->assignRole('admin_puskesmas');

        $this->notify = Mockery::spy(NotifyService::class);
        $this->app->instance(NotifyService::class, $this->notify);
        $this->app->instance(RealtimeBroadcastService::class, Mockery::spy(RealtimeBroadcastService::class));
    }

    private function batch(): PengirimanSampel
    {
        return PengirimanSampel::create(['puskesmas_id' => $this->puskesmas->id, 'status' => 'draft', 'dibuat_oleh' => $this->staf->id]);
    }

    private function tambahPasienBaru(PengirimanSampel $batch): PengirimanSampelPasien
    {
        Sanctum::actingAs($this->staf);
        $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/pasien", [
            'name' => 'Siti Aminah', 'nik' => '3529014101650001', 'gender' => 'P', 'tempat_lahir' => 'Sumenep',
            'tgl_lahir' => '1965-01-01', 'phone' => '081234567890', 'alamat' => 'Jl. Uji No. 1', 'jenis_prolanis' => 'DM',
        ])->assertCreated();

        return $batch->pasien()->firstOrFail();
    }

    private function pasienSilakes(array $overrides = []): array
    {
        return array_merge([
            'patient_id' => 9001, 'no_reg' => '2171126000123', 'name' => 'SITI AMINAH', 'nik' => '3529014101650001',
            'nik_hash' => 'HASH-9001', 'gender' => 'P', 'tgl_lahir' => '1965-01-01', 'phone' => '081234567890',
            'alamat' => 'Jl. Uji No. 1', 'rt_rw' => null, 'kel_desa' => null, 'kecamatan' => null,
            'is_prolanis' => true, 'jenis_prolanis' => 'DM', 'is_perokok' => false, 'jenis_perokok' => null,
            'no_bpjs' => null, 'updated_at' => now()->toIso8601String(),
        ], $overrides);
    }

    private function kirimEvent(array $event, ?string $secret = 'rahasia-uji'): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($event);
        $ts = (string) now()->timestamp;

        return $this->call('POST', '/api/v1/integration/silakes-events', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body.'.'.$ts, (string) $secret),
            'HTTP_X_TIMESTAMP' => $ts,
        ], $body);
    }

    private function jalankanJobDaftar(PengirimanSampelPasien $baris): void
    {
        app()->call([new RegisterPasienBaruToSilakesJob($baris->id), 'handle']);
    }

    // ------------------------------------------------------------------ pendaftaran langsung

    public function test_pasien_baru_langsung_dikirim_untuk_didaftarkan_dan_tercatat_pengisinya(): void
    {
        Queue::fake();
        $baris = $this->tambahPasienBaru($this->batch());

        $this->assertSame('mengirim', $baris->registrasi_status);
        $this->assertSame($this->staf->id, $baris->diinput_oleh_user_id);
        Queue::assertPushed(RegisterPasienBaruToSilakesJob::class, fn ($j) => $j->barisId === $baris->id);
    }

    public function test_job_daftar_menyimpan_id_usulan_dan_mengirim_identitas_serta_pengaju(): void
    {
        Queue::fake();
        $baris = $this->tambahPasienBaru($this->batch());
        Http::fake(['*/patient-registrations' => Http::response(['status' => 'success', 'message' => 'ok',
            'data' => ['registration_proposal_id' => 55, 'status' => 'pending_review', 'patient' => null]])]);

        $this->jalankanJobDaftar($baris);

        $baris->refresh();
        $this->assertSame(55, (int) $baris->registration_proposal_ref);
        $this->assertSame('menunggu_verifikasi', $baris->registrasi_status);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/v1/integration/patient-registrations')
            && $r['nik'] === '3529014101650001'
            && $r['puskesmas_asal'] === 'Puskesmas Batuan'
            && $r['fktp'] === 'Puskesmas Batuan'
            && $r['produli_pengaju_nama'] === 'Admin Batuan'
            && $r['produli_pengiriman_sampel_pasien_id'] === $baris->id);
    }

    public function test_nik_ternyata_sudah_terdaftar_baris_langsung_ditautkan_ke_pasien_itu(): void
    {
        Queue::fake();
        $baris = $this->tambahPasienBaru($this->batch());
        Http::fake(['*/patient-registrations' => Http::response(['status' => 'success', 'message' => 'ok',
            'data' => ['status' => 'sudah_terdaftar', 'registration_proposal_id' => null, 'patient' => $this->pasienSilakes()]])]);

        $this->jalankanJobDaftar($baris);

        $baris->refresh();
        $this->assertSame(9001, (int) $baris->external_patient_id);
        $this->assertSame('terdaftar', $baris->registrasi_status);
        $this->assertSame('2171126000123', $baris->registrasi_no_reg);
        $pasien = PatientsCache::where('external_patient_id', 9001)->firstOrFail();
        $this->assertSame($this->puskesmas->id, $pasien->puskesmas_id, 'wilayah tak terpetakan: ikut puskesmas pengirim');
        $this->notify->shouldHaveReceived('notify')->once();
    }

    public function test_event_disetujui_menautkan_pasien_menotifikasi_pengisi_sekali_saja(): void
    {
        Queue::fake();
        $baris = $this->tambahPasienBaru($this->batch());
        $baris->update(['registration_proposal_ref' => 55, 'registrasi_status' => 'menunggu_verifikasi']);

        $event = ['event_id' => 'evt-1', 'event' => 'patient_registration.approved', 'data' => [
            'registration_proposal_id' => 55, 'status' => 'approved', 'produli_pengiriman_sampel_pasien_id' => $baris->id,
            'catatan_reviewer' => null, 'patient' => $this->pasienSilakes(),
        ]];

        $this->kirimEvent($event)->assertOk()->assertJsonPath('data.diproses', true);
        $this->kirimEvent($event)->assertOk()->assertJsonPath('data.diproses', false);

        $baris->refresh();
        $this->assertFalse($baris->isPasienBaru());
        $this->assertSame('terdaftar', $baris->registrasi_status);
        $this->notify->shouldHaveReceived('notify')->once()->withArgs(fn ($target, $payload) => $target->user->id === $this->staf->id
            && str_contains($payload->body, '2171126000123'));
    }

    public function test_event_ditolak_menyimpan_alasan(): void
    {
        Queue::fake();
        $baris = $this->tambahPasienBaru($this->batch());
        $baris->update(['registration_proposal_ref' => 56, 'registrasi_status' => 'menunggu_verifikasi']);

        $this->kirimEvent(['event_id' => 'evt-2', 'event' => 'patient_registration.rejected', 'data' => [
            'registration_proposal_id' => 56, 'status' => 'rejected', 'produli_pengiriman_sampel_pasien_id' => $baris->id,
            'catatan_reviewer' => 'Foto KTP buram',
        ]])->assertOk();

        $baris->refresh();
        $this->assertSame('ditolak', $baris->registrasi_status);
        $this->assertSame('Foto KTP buram', $baris->registrasi_catatan);
        $this->notify->shouldHaveReceived('notify')->once();
    }

    public function test_event_tanpa_tanda_tangan_benar_ditolak(): void
    {
        $this->kirimEvent(['event_id' => 'evt-x', 'event' => 'patient_registration.approved', 'data' => ['status' => 'approved']], 'kunci-salah')
            ->assertStatus(401);
        $this->assertDatabaseCount('silakes_event_receipts', 0);
    }

    public function test_event_disetujui_lebih_dulu_tidak_ditimpa_balasan_pendaftaran(): void
    {
        Queue::fake();
        $baris = $this->tambahPasienBaru($this->batch());

        // Auto approve SiLAKES memutuskan sebelum balasan POST sampai.
        $this->kirimEvent(['event_id' => 'evt-3', 'event' => 'patient_registration.approved', 'data' => [
            'registration_proposal_id' => 57, 'status' => 'approved', 'produli_pengiriman_sampel_pasien_id' => $baris->id,
            'patient' => $this->pasienSilakes(),
        ]])->assertOk();

        Http::fake(['*/patient-registrations' => Http::response(['status' => 'success', 'message' => 'ok',
            'data' => ['registration_proposal_id' => 57, 'status' => 'pending_review', 'patient' => null]])]);
        $this->jalankanJobDaftar($baris->fresh());

        $this->assertSame('terdaftar', $baris->fresh()->registrasi_status);
    }

    public function test_sampel_tiba_membawa_id_pendaftaran_dan_pasien_terdaftar_dikirim_sebagai_pasien_lama(): void
    {
        Queue::fake();
        $batch = $this->batch();
        $menunggu = $this->tambahPasienBaru($batch);
        $menunggu->update(['registration_proposal_ref' => 58, 'registrasi_status' => 'menunggu_verifikasi']);
        $terdaftar = PengirimanSampelPasien::create(['pengiriman_sampel_id' => $batch->id, 'urutan' => 2,
            'nama_snapshot' => 'Budi', 'external_patient_id' => 9002, 'registrasi_status' => 'terdaftar']);

        $batch->update(['status' => 'tiba_labkesda', 'tiba_at' => now()]);
        Http::fake(['*/prolanis-deliveries' => Http::response(['status' => 'success', 'message' => 'ok',
            'data' => ['silakes_delivery_id' => 9, 'items' => []]])]);
        (new SendProlanisDeliveryToSilakesJob($batch->id))->handle(app(SilakesApiClient::class));

        Http::assertSent(fn (HttpRequest $r) => $r['pasien'][0]['kind'] === 'proposal'
            && $r['pasien'][0]['registration_proposal_id'] === 58
            && $r['pasien'][1] === ['kind' => 'existing', 'patient_id' => 9002]);
    }

    public function test_pengecekan_berkala_menerapkan_keputusan_bila_event_tidak_sampai(): void
    {
        Queue::fake();
        $baris = $this->tambahPasienBaru($this->batch());
        $baris->update(['registration_proposal_ref' => 59, 'registrasi_status' => 'menunggu_verifikasi', 'registrasi_diperbarui_at' => now()->subMinutes(10)]);
        Http::fake(['*/patient-registrations/59' => Http::response(['status' => 'success', 'message' => 'ok', 'data' => [
            'registration_proposal_id' => 59, 'status' => 'approved', 'produli_pengiriman_sampel_pasien_id' => $baris->id,
            'patient' => $this->pasienSilakes(),
        ]])]);

        $this->artisan('produli:cek-pendaftaran-silakes')->assertSuccessful();

        $this->assertSame('terdaftar', $baris->fresh()->registrasi_status);
    }

    // ------------------------------------------------------------------ usulan perubahan data

    public function test_keputusan_usulan_perubahan_dinotifikasi_sekali_setelah_semua_field_diputuskan(): void
    {
        $pasien = PatientsCache::create(['external_patient_id' => 9003, 'nik_hash' => 'HASH-9003', 'nama' => 'Budi',
            'puskesmas_id' => $this->puskesmas->id, 'wilayah_status' => 'unknown', 'phone' => '081200000000']);

        Http::fake(['*/pembaruan-lapangan' => Http::response(['status' => 'success', 'message' => 'ok',
            'data' => ['update_ids' => [301, 302], 'status' => 'pending_review']])]);
        $job = new SyncPatientFieldUpdateToSilakesJob($pasien->id, ['phone' => '081211112222', 'pekerjaan' => 'Guru'], 'Admin Batuan');
        $job->proposedByUserId = $this->staf->id;
        $job->handle(app(SilakesApiClient::class));

        $this->assertSame(2, SilakesFieldUpdateRef::where('user_id', $this->staf->id)->count());

        $this->kirimEvent(['event_id' => 'evt-4', 'event' => 'patient_field_update.approved', 'data' => [
            'update_id' => 301, 'patient' => $this->pasienSilakes(['patient_id' => 9003, 'name' => 'Budi', 'nik_hash' => 'HASH-9003', 'phone' => '081211112222']),
        ]])->assertOk();
        $this->notify->shouldNotHaveReceived('notify');
        $this->assertSame('081211112222', $pasien->fresh()->phone, 'data pasien langsung berubah, tidak menunggu sinkron 02:00');

        $this->kirimEvent(['event_id' => 'evt-5', 'event' => 'patient_field_update.rejected', 'data' => [
            'update_id' => 302, 'catatan_reviewer' => 'Pekerjaan tidak sesuai KTP',
        ]])->assertOk();
        $this->notify->shouldHaveReceived('notify')->once()->withArgs(fn ($target, $payload) => $target->user->id === $this->staf->id
            && str_contains($payload->body, '1 disetujui, 1 ditolak')
            && str_contains($payload->body, 'Pekerjaan tidak sesuai KTP'));
    }

    // ------------------------------------------------------------------ cari sambil mengetik

    public function test_cari_pasien_nama_nik_dan_bpjs_maks_10_dalam_puskesmas_sendiri(): void
    {
        $lain = Puskesmas::create(['kabupaten_id' => $this->puskesmas->kabupaten_id, 'kode_internal' => 'PKM-LAIN', 'nama' => 'Puskesmas Lain']);
        foreach (range(1, 12) as $i) {
            PatientsCache::create(['external_patient_id' => 7000 + $i, 'nik_hash' => "H-{$i}", 'nama' => "Siti Pasien {$i}",
                'puskesmas_id' => $this->puskesmas->id, 'wilayah_status' => 'unknown', 'is_prolanis' => $i % 2 === 0]);
        }
        PatientsCache::create(['external_patient_id' => 7100, 'nik_hash' => 'H-NIK', 'nama' => 'Budi Target', 'nik' => '3529010101800001',
            'no_bpjs' => '0001234567890', 'puskesmas_id' => $this->puskesmas->id, 'wilayah_status' => 'unknown']);
        PatientsCache::create(['external_patient_id' => 7200, 'nik_hash' => 'H-LAIN', 'nama' => 'Siti Puskesmas Lain',
            'puskesmas_id' => $lain->id, 'wilayah_status' => 'unknown']);

        Sanctum::actingAs($this->staf);
        $hasil = $this->getJson('/api/v1/pengiriman-sampel/patient-search?q=siti')->assertOk()->json('data.items');
        $this->assertCount(10, $hasil);
        $this->assertTrue($hasil[0]['is_prolanis'], 'Prolanis didahulukan');
        $this->assertNotContains('Siti Puskesmas Lain', array_column($hasil, 'nama'));

        $this->getJson('/api/v1/pengiriman-sampel/patient-search?q=3529-0101')->assertOk()->assertJsonPath('data.items.0.nama', 'Budi Target');
        $this->getJson('/api/v1/pengiriman-sampel/patient-search?q=00012345')->assertOk()->assertJsonPath('data.items.0.nama', 'Budi Target');
        $this->getJson('/api/v1/pengiriman-sampel/patient-search?q=s')->assertOk()->assertJsonCount(0, 'data.items');

        $batch = $this->batch();
        PengirimanSampelPasien::create(['pengiriman_sampel_id' => $batch->id, 'urutan' => 1, 'nama_snapshot' => 'Budi Target', 'external_patient_id' => 7100]);
        $this->getJson("/api/v1/pengiriman-sampel/patient-search?q=budi&batch_id={$batch->id}")->assertOk()
            ->assertJsonPath('data.items.0.sudah_di_antrian', true);
    }
}
