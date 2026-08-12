<?php

namespace Tests\Feature\Jobs;

use App\Exceptions\SilakesApiException;
use App\Jobs\SyncFieldUpdateToSilakesJob;
use App\Models\Kabupaten;
use App\Models\Kader;
use App\Models\PatientsCache;
use App\Models\Puskesmas;
use App\Models\User;
use App\Models\VisitAssignment;
use App\Models\VisitReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regresi untuk SyncFieldUpdateToSilakesJob (docs/planning/02 §2c) — job TERPISAH yang push
 * balik geo-verifikasi kader ke SiLAKES setelah laporan kunjungan tersimpan lokal.
 *
 * Dispatch dilakukan LEWAT queue sungguhan (bukan panggil handle() langsung, bukan Queue::fake())
 * supaya $this->fail()/failed() callback benar-benar tersambung ke mesin queue Laravel — di
 * lingkungan test QUEUE_CONNECTION=sync (phpunit.xml), jadi tetap jalan inline tapi lewat jalur
 * asli (Illuminate\Queue\SyncQueue -> CallQueuedHandler), bukan cuma manggil method PHP biasa.
 */
class SyncFieldUpdateToSilakesJobTest extends TestCase
{
    use RefreshDatabase;

    private VisitReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        // Job ini resolve SilakesApiClient sungguhan lewat container (bukan lewat Queue::fake()),
        // jadi butuh write_token terisi -- SILAKES_WRITE_API_TOKEN di .env sengaja kosong sampai
        // diisi manual oleh operator (docs/planning/01 §9), jadi override di sini untuk test.
        Config::set('produli.silakes.write_token', 'test-write-token-untuk-job');

        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $puskesmas = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-A', 'nama' => 'Puskesmas A']);

        $kaderUser = User::factory()->create(['name' => 'Bu Siti']);
        $kader = Kader::create(['user_id' => $kaderUser->id, 'puskesmas_id' => $puskesmas->id, 'status_aktif' => true]);

        $patient = PatientsCache::create([
            'external_patient_id' => 960001,
            'nik_hash' => 'HASH-960001',
            'nama' => 'Pasien Uji Job',
            'wilayah_status' => 'resolved',
            'puskesmas_id' => $puskesmas->id,
        ]);

        $assignment = VisitAssignment::create([
            'patient_id' => $patient->id,
            'kader_id' => $kader->id,
            'scheduled_date' => '2026-08-05',
            'status' => 'completed',
            'priority' => 'sedang',
            'puskesmas_id_snapshot' => $puskesmas->id,
        ]);

        $this->report = VisitReport::create([
            'assignment_id' => $assignment->id,
            'gps_lat' => -7.0200,
            'gps_lng' => 113.8500,
            'photo_path' => 'visit-photos/dummy.jpg',
            'kondisi' => 'Kondisi stabil.',
            'geo_status' => 'verified',
            'geo_source' => 'kader_verified',
            'latitude' => -7.0200,
            'longitude' => 113.8500,
            'sync_status' => 'pending',
        ]);
    }

    public function test_sukses_menandai_synced_dan_mengirim_payload_yang_benar(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'message' => 'ok', 'data' => null], 200)]);

        SyncFieldUpdateToSilakesJob::dispatch($this->report->id);

        $fresh = $this->report->fresh();
        $this->assertSame('synced', $fresh->sync_status);
        $this->assertNotNull($fresh->synced_at);
        $this->assertNull($fresh->sync_error);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return str_contains($request->url(), '/api/v1/integration/patients/960001/pembaruan-lapangan')
                && $body['kopipu_visit_id'] === $this->report->id
                && $body['kopipu_kader_nama'] === 'Bu Siti'
                && (float) $body['latitude'] === -7.0200
                && (float) $body['longitude'] === 113.8500;
        });
    }

    public function test_patient_field_updates_ikut_terkirim_di_payload(): void
    {
        $this->report->update([
            'patient_field_updates' => [
                'alamat' => 'Jl. Melati No. 5',
                'kel_desa' => 'Desa Uji',
                'golongan_darah' => 'O',
                'agama' => 'Islam',
                'is_bpjs' => true,
                'no_bpjs' => '0001122334455',
                'jenis_prolanis' => 'DM',
                'jenis_perokok' => 'tidak_merokok',
            ],
        ]);

        Http::fake(['*' => Http::response(['status' => 'success', 'message' => 'ok', 'data' => null], 200)]);

        SyncFieldUpdateToSilakesJob::dispatch($this->report->id);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['alamat'] === 'Jl. Melati No. 5'
                && $body['kel_desa'] === 'Desa Uji'
                && $body['golongan_darah'] === 'O'
                && $body['agama'] === 'Islam'
                && $body['is_bpjs'] === true
                && $body['no_bpjs'] === '0001122334455'
                && $body['jenis_prolanis'] === 'DM'
                && $body['jenis_perokok'] === 'tidak_merokok'
                // Field tetap (sumber/kopipu_visit_id/kopipu_kader_nama) tidak boleh tertimpa.
                && $body['sumber'] === 'kopipu_kunjungan'
                && $body['kopipu_visit_id'] === $this->report->id;
        });
    }

    public function test_4xx_menandai_gagal_permanen_tanpa_melempar_exception_ke_pemanggil(): void
    {
        Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'Data tidak valid'], 422)]);

        // 4xx tidak akan membaik dengan retry -> job harus fail bersih, TIDAK boleh melempar
        // exception ke pemanggil (dispatch() ini harus selesai tanpa exception).
        SyncFieldUpdateToSilakesJob::dispatch($this->report->id);

        $fresh = $this->report->fresh();
        $this->assertSame('failed', $fresh->sync_status);
        $this->assertStringContainsString('Data tidak valid', $fresh->sync_error);
    }

    public function test_5xx_melempar_exception_supaya_tidak_ditelan_diam_diam(): void
    {
        Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'Server error'], 500)]);

        // 5xx harus tetap "berisik" (exception keluar), bukan ditelan diam-diam -- di worker
        // queue sungguhan (database/redis), ini yang membuat $tries/backoff() di job benar-benar
        // dipakai untuk retry. Di lingkungan test (QUEUE_CONNECTION=sync), SyncQueue Laravel
        // memperlakukan exception yang keluar dari handle() sebagai kegagalan langsung (tanpa
        // retry beneran, karena tidak ada worker) DAN tetap melempar ulang ke pemanggil.
        $this->expectException(SilakesApiException::class);

        SyncFieldUpdateToSilakesJob::dispatch($this->report->id);
    }

    public function test_tries_dan_backoff_terkonfigurasi_untuk_retry_menaik(): void
    {
        $job = new SyncFieldUpdateToSilakesJob($this->report->id);

        $this->assertSame(5, $job->tries);
        $this->assertSame([60, 300, 900, 3600, 14400], $job->backoff());
    }

    public function test_report_yang_sudah_synced_tidak_dipanggil_ulang(): void
    {
        $this->report->update(['sync_status' => 'synced', 'synced_at' => now()]);
        Http::fake();

        SyncFieldUpdateToSilakesJob::dispatch($this->report->id);

        Http::assertNothingSent();
    }

    public function test_report_yang_sudah_dihapus_tidak_error(): void
    {
        Http::fake();

        SyncFieldUpdateToSilakesJob::dispatch($this->report->id + 99999);

        Http::assertNothingSent();
    }
}
