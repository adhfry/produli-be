<?php

namespace Tests\Feature\Silakes;

use App\Exceptions\SinkronSedangBerjalanException;
use App\Models\IntegrationSyncLog;
use App\Models\PatientsCache;
use App\Models\User;
use App\Services\Silakes\SyncSilakesService;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Revisi 2026-11-17 (temuan analisis data pasien Gapura):
 * - produli:isi-ulang-pasien mengisi NIK/no. BPJS yang kosong tanpa menimpa apa pun;
 * - sinkron harian, tombol manual, dan isi-ulang memakai satu kunci (tidak tumpang tindih);
 * - batas "data baru" pasien tidak lagi bergeser karena event realtime.
 */
class IsiUlangDanKunciSinkronTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        Storage::fake('local');
    }

    private function pasien(int $ext, array $overrides = []): PatientsCache
    {
        return PatientsCache::create(array_merge([
            'external_patient_id' => $ext, 'nik_hash' => "HASH-{$ext}", 'nama' => "Pasien {$ext}",
            'wilayah_status' => 'unknown', 'last_synced_at' => '2026-07-01 10:00:00',
        ], $overrides));
    }

    private function baris(int $ext, array $overrides = []): array
    {
        return array_merge([
            'patient_id' => $ext, 'no_reg' => "REG-{$ext}", 'name' => "Pasien {$ext}", 'nik' => "352901010180{$ext}",
            'nik_hash' => "HASH-{$ext}", 'no_bpjs' => "000{$ext}000000", 'updated_at' => '2026-07-01T10:00:00+07:00',
        ], $overrides);
    }

    private function fakePasien(array $rows): void
    {
        Http::fake(['*/api/v1/integration/patients*' => Http::response(['status' => 'success', 'data' => $rows,
            'meta' => ['has_more' => false, 'next_cursor' => null]])]);
    }

    // ------------------------------------------------------------------ isi ulang

    public function test_mengisi_nik_dan_bpjs_yang_kosong_tanpa_menimpa_dan_tanpa_mengubah_batas_sinkron(): void
    {
        $kosong = $this->pasien(1001);
        $sudahAda = $this->pasien(1002, ['nik' => '3529010101800999', 'no_bpjs' => '0009999999999']);
        $hashBeda = $this->pasien(1003, ['nik_hash' => 'HASH-LAIN']);
        $this->fakePasien([$this->baris(1001), $this->baris(1002), $this->baris(1003), $this->baris(1004)]);

        $this->artisan('produli:isi-ulang-pasien')->assertSuccessful();

        $kosong->refresh();
        $this->assertSame('3529010101801001', $kosong->nik);
        $this->assertSame('0001001000000', $kosong->no_bpjs);
        $this->assertSame('2026-07-01 10:00:00', $kosong->last_synced_at->format('Y-m-d H:i:s'), 'batas sinkron tidak berubah');

        $sudahAda->refresh();
        $this->assertSame('3529010101800999', $sudahAda->nik, 'nilai yang sudah ada tidak ditimpa');
        $this->assertSame('0009999999999', $sudahAda->no_bpjs);

        $hashBeda->refresh();
        $this->assertNull($hashBeda->nik, 'NIK tidak diisi bila nik_hash tidak cocok');
        $this->assertSame('0001003000000', $hashBeda->no_bpjs, 'kolom lain tetap boleh diisi');

        $this->assertSame(0, PatientsCache::where('external_patient_id', 1004)->count(), 'pasien yang tidak ada di PRODULI tidak dibuat');

        $log = IntegrationSyncLog::where('service_name', 'IsiUlangPasienCommand')->firstOrFail();
        $this->assertSame(3, $log->records_count);
        $this->assertSame(1, $log->details['nik_hash_beda']);
        Storage::disk('local')->assertExists($log->details['berkas']);
    }

    public function test_mode_uji_tidak_menulis_apa_pun(): void
    {
        $p = $this->pasien(1001);
        $this->fakePasien([$this->baris(1001)]);

        $this->artisan('produli:isi-ulang-pasien --uji')->assertSuccessful()->expectsOutputToContain('UJI');

        $this->assertNull($p->fresh()->nik);
        $this->assertNull($p->fresh()->no_bpjs);
        $this->assertSame(0, IntegrationSyncLog::count());
    }

    public function test_kolom_tidak_dikenal_ditolak(): void
    {
        $this->artisan('produli:isi-ulang-pasien --kolom=nama')->assertFailed();
    }

    // ------------------------------------------------------------------ kunci bersama

    public function test_isi_ulang_tidak_jalan_saat_sinkron_lain_memegang_kunci(): void
    {
        $p = $this->pasien(1001);
        $this->fakePasien([$this->baris(1001)]);
        $kunci = Cache::lock(SyncSilakesService::KUNCI, 60);
        $kunci->get();

        $this->artisan('produli:isi-ulang-pasien')->assertFailed();
        $this->assertNull($p->fresh()->nik);
        Http::assertNothingSent();

        $kunci->release();
    }

    public function test_sinkron_harian_dan_tombol_manual_menunggu_kunci(): void
    {
        $kunci = Cache::lock(SyncSilakesService::KUNCI, 60);
        $kunci->get();

        try {
            app(SyncSilakesService::class)->runAndLog();
            $this->fail('runAndLog seharusnya menolak saat kunci dipegang');
        } catch (SinkronSedangBerjalanException) {
            $this->assertSame(0, IntegrationSyncLog::count(), 'tidak dicatat sebagai sinkron gagal');
        }

        $this->artisan('produli:sync-silakes --force')->assertSuccessful()->expectsOutputToContain('Skip');

        $this->seed(RolesSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/silakes/sync')->assertStatus(409);

        $kunci->release();
    }

    // ------------------------------------------------------------------ batas "data baru"

    public function test_batas_sinkron_pasien_tidak_bergeser_karena_event_realtime(): void
    {
        IntegrationSyncLog::create(['service_name' => 'SyncSilakesService', 'endpoint' => 'patients+lab-results',
            'requested_at' => now(), 'status' => 'success', 'records_count' => 1,
            'details' => ['patients_watermark' => '2026-11-17T02:00:00+07:00']]);
        // Event realtime siang hari menyimpan satu pasien dengan updated_at lebih baru.
        $this->pasien(2001, ['last_synced_at' => '2026-11-17 10:00:00']);

        $this->assertSame('2026-11-17T02:00:00+07:00', app(SyncSilakesService::class)->patientsSince());

        Http::fake([
            '*/api/v1/integration/patients*' => Http::response(['status' => 'success', 'data' => [], 'meta' => ['has_more' => false, 'next_cursor' => null]]),
            '*/api/v1/integration/lab-results*' => Http::response(['status' => 'success', 'data' => [], 'meta' => ['has_more' => false, 'next_cursor' => null]]),
            '*/api/v1/integration/reference-ranges*' => Http::response(['status' => 'success', 'data' => ['aliases' => [], 'ranges' => []]]),
        ]);
        app(SyncSilakesService::class)->runAndLog();

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/integration/patients')
            && $r['since'] === '2026-11-17T02:00:00+07:00');
        $this->assertSame('2026-11-17T02:00:00+07:00', IntegrationSyncLog::latest('id')->first()->details['patients_watermark'],
            'tanpa pasien baru, batas tetap');
    }

    public function test_tanpa_batas_tersimpan_memakai_cara_lama_sebagai_awal(): void
    {
        $this->pasien(3001, ['last_synced_at' => '2026-11-16 14:54:44']);

        $this->assertStringStartsWith('2026-11-16T14:54:44', app(SyncSilakesService::class)->patientsSince());
    }
}
