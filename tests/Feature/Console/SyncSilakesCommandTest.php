<?php

namespace Tests\Feature\Console;

use App\Models\IntegrationSyncLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regresi throttle produli:sync-silakes -- ambang minimal jam sejak run sukses terakhir
 * (lihat docblock SyncSilakesCommand). Fokus utama: skenario jitter cron yang jadi akar
 * bug nyata "auto-sync skip tiap hari kedua" (laporan user, dikonfirmasi via audit
 * integration_sync_logs produksi -- pola persis 02:00:0X WIB tiap kali, TIDAK ADA baris
 * 'failed', murni race ambang 24 jam vs jitter beberapa detik).
 */
class SyncSilakesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // SyncSilakesService::run() dipanggil sungguhan di sini (bukan di-mock) -- fake semua
    // endpoint SiLAKES yang dipakainya supaya command benar-benar sampai ke runAndLog()
    // tanpa menyentuh jaringan, konsisten dgn pola SyncSilakesServiceTest.
    private function fakeEmptySilakesResponses(): void
    {
        Http::fake([
            '*/api/v1/integration/patients*' => Http::response(['status' => 'success', 'data' => [], 'meta' => ['has_more' => false, 'next_cursor' => null]], 200),
            '*/api/v1/integration/lab-results*' => Http::response(['status' => 'success', 'data' => [], 'meta' => ['has_more' => false, 'next_cursor' => null]], 200),
            '*/api/v1/integration/reference-ranges*' => Http::response(['status' => 'success', 'data' => ['aliases' => [], 'ranges' => []]], 200),
        ]);
    }

    public function test_skip_kalau_run_sukses_terakhir_belum_23_jam(): void
    {
        $this->fakeEmptySilakesResponses();
        IntegrationSyncLog::create([
            'service_name' => 'SyncSilakesService', 'endpoint' => 'patients+lab-results',
            'requested_at' => now()->subHours(20), 'status' => 'success', 'records_count' => 5, 'details' => [],
        ]);

        $this->artisan('produli:sync-silakes')
            ->expectsOutputToContain('Skip')
            ->assertExitCode(0);

        $this->assertSame(1, IntegrationSyncLog::count());
    }

    public function test_jalan_kalau_run_sukses_terakhir_sudah_lebih_dari_23_jam(): void
    {
        $this->fakeEmptySilakesResponses();
        IntegrationSyncLog::create([
            'service_name' => 'SyncSilakesService', 'endpoint' => 'patients+lab-results',
            'requested_at' => now()->subHours(24), 'status' => 'success', 'records_count' => 5, 'details' => [],
        ]);

        $this->artisan('produli:sync-silakes')->assertExitCode(0);

        $this->assertSame(2, IntegrationSyncLog::count());
    }

    /**
     * REGRESI -- reproduksi persis bug produksi: run kemarin mendarat di 02:00:06 (jitter
     * cron wajar), schedule:run hari ini dipanggil sistem cron di 02:00:01 (5 detik LEBIH
     * AWAL dari detik kemarin, jitter yang sama-sama normal) -- selisih waktu SUNGGUHAN
     * 23 jam 59 menit 55 detik, TETAP HARUS dianggap "sudah lewat 23 jam" dan jalan, BUKAN
     * skip. Dengan ambang lama (24 jam persis) ini akan gagal (23j59m55d < 24j0m0d), itulah
     * akar bug "auto-sync skip tiap hari kedua" yang dilaporkan user.
     */
    public function test_jitter_beberapa_detik_pada_trigger_harian_tidak_lagi_menyebabkan_skip(): void
    {
        $this->fakeEmptySilakesResponses();
        $kemarin = Carbon::parse('2026-08-24 02:00:06');
        Carbon::setTestNow($kemarin);
        IntegrationSyncLog::create([
            'service_name' => 'SyncSilakesService', 'endpoint' => 'patients+lab-results',
            'requested_at' => $kemarin, 'status' => 'success', 'records_count' => 5, 'details' => [],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-25 02:00:01'));

        $this->artisan('produli:sync-silakes')->assertExitCode(0);

        $this->assertSame(2, IntegrationSyncLog::where('status', 'success')->count());
    }

    public function test_force_melewati_throttle(): void
    {
        $this->fakeEmptySilakesResponses();
        IntegrationSyncLog::create([
            'service_name' => 'SyncSilakesService', 'endpoint' => 'patients+lab-results',
            'requested_at' => now()->subHour(), 'status' => 'success', 'records_count' => 5, 'details' => [],
        ]);

        $this->artisan('produli:sync-silakes', ['--force' => true])->assertExitCode(0);

        $this->assertSame(2, IntegrationSyncLog::count());
    }
}
