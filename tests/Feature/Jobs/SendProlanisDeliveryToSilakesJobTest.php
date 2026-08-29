<?php

namespace Tests\Feature\Jobs;

use App\Exceptions\SilakesApiException;
use App\Jobs\SendProlanisDeliveryToSilakesJob;
use App\Models\Kabupaten;
use App\Models\PatientsCache;
use App\Models\PengirimanSampel;
use App\Models\Puskesmas;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase D modul "Kirim Data Prolanis ke Labkesda Sumenep" -- job TERPISAH push batch pengiriman
 * sampel ke SiLAKES setelah kurir konfirmasi tiba (dispatch dari
 * PengirimanSampelService::confirmArrival()). Dispatch LEWAT queue sungguhan (bukan Queue::fake())
 * -- pola sama persis SyncFieldUpdateToSilakesJobTest, lihat docblock di sana.
 */
class SendProlanisDeliveryToSilakesJobTest extends TestCase
{
    use RefreshDatabase;

    private PengirimanSampel $batch;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('produli.silakes.write_token', 'test-write-token-untuk-job');

        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $puskesmas = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-A', 'nama' => 'Puskesmas A']);
        $creator = User::factory()->create(['puskesmas_id' => $puskesmas->id]);

        $this->batch = PengirimanSampel::create([
            'puskesmas_id' => $puskesmas->id,
            'status' => 'tiba_labkesda',
            'dibuat_oleh' => $creator->id,
            'tiba_at' => now(),
        ]);

        $existingPatient = PatientsCache::create([
            'external_patient_id' => 970001,
            'nik_hash' => 'HASH-970001',
            'nama' => 'Pasien Existing',
            'wilayah_status' => 'resolved',
            'puskesmas_id' => $puskesmas->id,
        ]);

        $this->batch->pasien()->create([
            'external_patient_id' => $existingPatient->external_patient_id,
            'nama_snapshot' => 'Pasien Existing',
            'urutan' => 1,
        ]);
        $this->batch->pasien()->create([
            'nama_snapshot' => 'Pasien Baru',
            'jenis_prolanis_snapshot' => 'HT',
            'urutan' => 2,
            'data_pasien_baru_nik' => '3529010101650001',
            'data_pasien_baru_gender' => 'P',
            'data_pasien_baru_tempat_lahir' => 'Sumenep',
            'data_pasien_baru_tgl_lahir' => '1965-01-01',
            'data_pasien_baru_phone' => '081234567890',
            'data_pasien_baru_alamat' => 'Jl. Uji No. 1',
        ]);
    }

    public function test_sukses_kirim_payload_lengkap_dan_simpan_batch_ref(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'success',
            'message' => 'ok',
            'data' => [
                'silakes_delivery_id' => 555,
                'items' => [
                    ['index' => 0, 'patient_id' => 970001, 'status' => 'resolved'],
                    ['index' => 1, 'registration_proposal_id' => 888, 'status' => 'pending_review'],
                ],
            ],
        ], 200)]);

        SendProlanisDeliveryToSilakesJob::dispatch($this->batch->id);

        $fresh = $this->batch->fresh();
        $this->assertSame(555, $fresh->silakes_batch_ref);

        $pasienBaru = $fresh->pasien()->whereNull('external_patient_id')->first();
        $this->assertSame(888, $pasienBaru->registration_proposal_ref);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return str_contains($request->url(), '/api/v1/integration/prolanis-deliveries')
                && $body['produli_pengiriman_sampel_id'] === $this->batch->id
                && count($body['pasien']) === 2
                && $body['pasien'][0]['kind'] === 'existing'
                && $body['pasien'][0]['patient_id'] === 970001
                && $body['pasien'][1]['kind'] === 'proposal'
                && $body['pasien'][1]['name'] === 'Pasien Baru'
                && $body['pasien'][1]['jenis_prolanis'] === 'HT';
        });
    }

    public function test_batch_yang_sudah_punya_silakes_batch_ref_tidak_dikirim_ulang(): void
    {
        $this->batch->update(['silakes_batch_ref' => 999]);
        Http::fake();

        SendProlanisDeliveryToSilakesJob::dispatch($this->batch->id);

        Http::assertNothingSent();
    }

    public function test_4xx_gagal_permanen_tanpa_melempar_exception(): void
    {
        Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'Data tidak valid'], 422)]);

        SendProlanisDeliveryToSilakesJob::dispatch($this->batch->id);

        $this->assertNull($this->batch->fresh()->silakes_batch_ref);
    }

    public function test_5xx_melempar_exception(): void
    {
        Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'Server error'], 500)]);

        $this->expectException(SilakesApiException::class);

        SendProlanisDeliveryToSilakesJob::dispatch($this->batch->id);
    }

    public function test_tries_dan_backoff_terkonfigurasi(): void
    {
        $job = new SendProlanisDeliveryToSilakesJob($this->batch->id);

        $this->assertSame(5, $job->tries);
        $this->assertSame([60, 300, 900, 3600, 14400], $job->backoff());
    }
}
