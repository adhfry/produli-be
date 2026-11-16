<?php

namespace Tests\Feature\Patient;

use App\Jobs\SendProlanisDeliveryToSilakesJob;
use App\Jobs\SyncPatientFieldUpdateToSilakesJob;
use App\Models\Kabupaten;
use App\Models\KtpScan;
use App\Models\PatientsCache;
use App\Models\PengirimanSampel;
use App\Models\Puskesmas;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Revisi 2026-11-16: scan KTP pasien (dibaca lewat OCR SiLAKES, foto disimpan di PRODULI dan
 * dikirim ke SiLAKES) + form usulan perubahan data yang lengkap seperti registrasi SiLAKES.
 */
class KtpScanDanUsulanLengkapTest extends TestCase
{
    use RefreshDatabase;

    private Puskesmas $puskesmas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
        Config::set('produli.storage.ktp_photos_disk', 'ktp-uji');
        Config::set('produli.silakes.write_token', 'test-write-token');
        Storage::fake('ktp-uji');

        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $this->puskesmas = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-KTP', 'nama' => 'Puskesmas KTP']);
    }

    private function staf(string $role = 'admin_puskesmas'): User
    {
        $user = User::factory()->create(['puskesmas_id' => $this->puskesmas->id]);
        $user->assignRole($role);

        return $user;
    }

    private function pasien(array $overrides = []): PatientsCache
    {
        return PatientsCache::create(array_merge([
            'external_patient_id' => 880001,
            'nik_hash' => 'HASH-880001',
            'nik' => '0000000000000555',
            'nama' => 'Pasien KTP',
            'puskesmas_id' => $this->puskesmas->id,
            'wilayah_status' => 'unknown',
        ], $overrides));
    }

    private function fakeOcrSilakes(array $fields, array $checks = []): void
    {
        Http::fake(['*/api/v1/integration/ktp-ocr' => Http::response([
            'status' => 'success', 'message' => 'KTP terbaca.',
            'data' => ['fields' => $fields, 'checks' => $checks, 'existing_patient_id' => null, 'model' => 'uji'],
        ])]);
    }

    public function test_scan_ktp_menyimpan_foto_dan_mengembalikan_hasil_ocr_silakes(): void
    {
        $this->fakeOcrSilakes(['nik' => '3529014101650001', 'name' => 'SITI AMINAH', 'gender' => 'P', 'tgl_lahir' => '1965-01-01']);
        $staf = $this->staf();
        Sanctum::actingAs($staf);

        $response = $this->postJson('/api/v1/ktp-scans', ['image' => UploadedFile::fake()->image('ktp.jpg', 800, 500)])
            ->assertCreated()
            ->assertJsonPath('data.fields.nik', '3529014101650001')
            ->assertJsonPath('data.ocr_error', null);

        $scan = KtpScan::findOrFail($response->json('data.id'));
        Storage::disk('ktp-uji')->assertExists($scan->path);
        $this->assertSame($staf->id, $scan->user_id);

        // Dibaca lewat endpoint integrasi SiLAKES, dengan token BACA dan tanda tangan HMAC.
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/v1/integration/ktp-ocr')
            && $r->hasHeader('X-Signature')
            && $r->header('Authorization')[0] === 'Bearer '.config('produli.silakes.token'));
    }

    public function test_scan_ktp_tetap_menyimpan_foto_saat_silakes_gagal_membaca(): void
    {
        Http::fake(['*/api/v1/integration/ktp-ocr' => Http::response(['status' => 'error', 'message' => 'Foto tidak terbaca sebagai KTP.'], 422)]);
        Sanctum::actingAs($this->staf());

        $response = $this->postJson('/api/v1/ktp-scans', ['image' => UploadedFile::fake()->image('ktp.jpg')])
            ->assertCreated()
            ->assertJsonPath('data.ocr_error', 'Foto tidak terbaca sebagai KTP.');

        Storage::disk('ktp-uji')->assertExists(KtpScan::findOrFail($response->json('data.id'))->path);
    }

    public function test_scan_ktp_hanya_untuk_staf_dan_menolak_berkas_besar(): void
    {
        Sanctum::actingAs($this->staf('kader'));
        $this->postJson('/api/v1/ktp-scans', ['image' => UploadedFile::fake()->image('ktp.jpg')])->assertForbidden();

        Sanctum::actingAs($this->staf());
        $this->postJson('/api/v1/ktp-scans', ['image' => UploadedFile::fake()->image('ktp.jpg')->size(3000)])
            ->assertStatus(422);
    }

    public function test_usulan_lengkap_dengan_scan_ktp_mengirim_foto_dan_sumber_produli_ktp_ke_silakes(): void
    {
        $this->fakeOcrSilakes(['nik' => '3529014101650001']);
        $staf = $this->staf();
        Sanctum::actingAs($staf);
        $pasien = $this->pasien();
        $scanId = $this->postJson('/api/v1/ktp-scans', ['image' => UploadedFile::fake()->image('ktp.jpg')])->json('data.id');

        Queue::fake();
        $this->patchJson("/api/v1/patients/{$pasien->id}/propose-update", [
            'nik' => '3529014101650001', 'name' => 'Siti Aminah', 'tempat_lahir' => 'Sumenep',
            'tgl_lahir' => '1965-01-01', 'gender' => 'P', 'phone' => '0812-3456-7890',
            'email' => 'siti@contoh.id', 'fktp' => 'Puskesmas KTP', 'ktp_scan_id' => $scanId,
        ])->assertOk();

        $job = null;
        Queue::assertPushed(SyncPatientFieldUpdateToSilakesJob::class, function ($j) use (&$job) {
            $job = $j;

            return true;
        });
        $this->assertSame($scanId, $job->ktpScanId);
        $this->assertSame('081234567890', $job->fields['phone'], 'nomor HP dirapikan sebelum dikirim');
        $this->assertNotNull(KtpScan::find($scanId)->used_at);

        Http::fake(['*/pembaruan-lapangan' => Http::response(['status' => 'success', 'message' => 'ok', 'data' => []])]);
        $job->handle(app(\App\Services\Silakes\SilakesApiClient::class));

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/v1/integration/patients/880001/pembaruan-lapangan')
            && $r['sumber'] === 'produli_ktp'
            && str_starts_with((string) $r['foto_ktp_base64'], 'data:image/jpeg;base64,')
            && $r['tempat_lahir'] === 'Sumenep');
    }

    public function test_usulan_tanpa_scan_tetap_sumber_produli_dashboard_tanpa_foto(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'message' => 'ok', 'data' => []])]);
        $pasien = $this->pasien();

        (new SyncPatientFieldUpdateToSilakesJob($pasien->id, ['phone' => '081234567890'], 'Staf'))
            ->handle(app(\App\Services\Silakes\SilakesApiClient::class));

        Http::assertSent(fn (HttpRequest $r) => $r['sumber'] === 'produli_dashboard' && ! isset($r['foto_ktp_base64']));
    }

    public function test_usulan_ditolak_bila_nik_tidak_cocok_tanggal_lahir_kecuali_dikonfirmasi(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->staf());
        $pasien = $this->pasien();

        $data = ['nik' => '3529014101650001', 'tgl_lahir' => '1970-05-05', 'gender' => 'P'];
        $this->patchJson("/api/v1/patients/{$pasien->id}/propose-update", $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors('tgl_lahir');
        Queue::assertNothingPushed();

        $this->patchJson("/api/v1/patients/{$pasien->id}/propose-update", [...$data, 'konfirmasi_beda_nik' => true])->assertOk();
        Queue::assertPushed(SyncPatientFieldUpdateToSilakesJob::class);
    }

    public function test_foto_scan_milik_staf_lain_tidak_bisa_dipakai(): void
    {
        $this->fakeOcrSilakes(['nik' => '3529014101650001']);
        Sanctum::actingAs($this->staf());
        $scanId = $this->postJson('/api/v1/ktp-scans', ['image' => UploadedFile::fake()->image('ktp.jpg')])->json('data.id');

        Queue::fake();
        Sanctum::actingAs($this->staf());
        $this->patchJson("/api/v1/patients/{$this->pasien()->id}/propose-update", ['phone' => '081234567890', 'ktp_scan_id' => $scanId])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ktp_scan_id');
        Queue::assertNothingPushed();
    }

    public function test_pasien_baru_pengiriman_sampel_membawa_identitas_lengkap_dan_foto_ke_silakes(): void
    {
        $this->fakeOcrSilakes(['nik' => '3529014101650001']);
        $staf = $this->staf();
        Sanctum::actingAs($staf);
        $scanId = $this->postJson('/api/v1/ktp-scans', ['image' => UploadedFile::fake()->image('ktp.jpg')])->json('data.id');

        $batch = PengirimanSampel::create(['puskesmas_id' => $this->puskesmas->id, 'status' => 'draft', 'dibuat_oleh' => $staf->id]);
        $this->postJson("/api/v1/pengiriman-sampel/{$batch->id}/pasien", [
            'name' => 'Siti Aminah', 'nik' => '3529014101650001', 'gender' => 'P', 'tempat_lahir' => 'Sumenep',
            'tgl_lahir' => '1965-01-01', 'phone' => '081234567890', 'alamat' => 'Jl. Uji No. 1',
            'agama' => 'ISLAM', 'status_perkawinan' => 'KAWIN', 'pekerjaan' => 'Petani', 'golongan_darah' => 'O',
            'fktp' => 'Puskesmas KTP', 'ktp_scan_id' => $scanId,
        ])->assertCreated();

        $baris = $batch->pasien()->firstOrFail();
        $this->assertSame('Petani', $baris->data_pasien_baru_pekerjaan);
        $this->assertSame($scanId, $baris->ktp_scan_id);

        $batch->update(['status' => 'tiba_labkesda', 'tiba_at' => now()]);
        Http::fake(['*/prolanis-deliveries' => Http::response([
            'status' => 'success', 'message' => 'ok',
            'data' => ['silakes_delivery_id' => 9, 'items' => [['index' => 0, 'patient_id' => null, 'registration_proposal_id' => 3, 'status' => 'pending_proposal']]],
        ])]);
        (new SendProlanisDeliveryToSilakesJob($batch->id))->handle(app(\App\Services\Silakes\SilakesApiClient::class));

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/v1/integration/prolanis-deliveries')
            && $r['pasien'][0]['pekerjaan'] === 'Petani'
            && $r['pasien'][0]['golongan_darah'] === 'O'
            && str_starts_with($r['pasien'][0]['foto_ktp_base64'], 'data:image/jpeg;base64,'));
    }
}
