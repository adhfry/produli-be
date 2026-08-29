<?php

namespace Tests\Feature\Console;

use App\Models\Kabupaten;
use App\Models\PengirimanSampel;
use App\Models\Puskesmas;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase D modul "Kirim Data Prolanis ke Labkesda Sumenep" -- poling status konfirmasi Labkesda
 * untuk batch berstatus 'tiba_labkesda' yang sudah punya silakes_batch_ref. Lihat docblock
 * PollProlanisDeliveryConfirmationCommand.
 */
class PollProlanisDeliveryConfirmationCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeBatch(?int $silakesBatchRef = null): PengirimanSampel
    {
        Config::set('produli.silakes.write_token', 'test-write-token');
        $kabupaten = Kabupaten::firstOrCreate(['kode_kemendagri' => '35.29'], ['nama' => 'Sumenep']);
        $puskesmas = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-'.uniqid(), 'nama' => 'Puskesmas A']);
        $creator = User::factory()->create(['puskesmas_id' => $puskesmas->id]);

        return PengirimanSampel::create([
            'puskesmas_id' => $puskesmas->id,
            'status' => 'tiba_labkesda',
            'dibuat_oleh' => $creator->id,
            'tiba_at' => now(),
            'silakes_batch_ref' => $silakesBatchRef,
        ]);
    }

    public function test_batch_yang_disetujui_worksheet_labkesda_maju_status_dan_notif(): void
    {
        $batch = $this->makeBatch(123);
        Http::fake(['*' => Http::response(['status' => 'success', 'message' => 'ok', 'data' => ['worksheet_status' => 'disetujui']], 200)]);

        $this->artisan('produli:poll-prolanis-delivery-confirmation')->assertSuccessful();

        $fresh = $batch->fresh();
        $this->assertSame('dikonfirmasi_labkesda', $fresh->status);
        $this->assertNotNull($fresh->dikonfirmasi_labkesda_at);
    }

    public function test_batch_dengan_worksheet_masih_draf_belum_dianggap_dikonfirmasi(): void
    {
        $batch = $this->makeBatch(789);
        Http::fake(['*' => Http::response(['status' => 'success', 'message' => 'ok', 'data' => ['worksheet_status' => 'draf']], 200)]);

        $this->artisan('produli:poll-prolanis-delivery-confirmation')->assertSuccessful();

        $this->assertSame('tiba_labkesda', $batch->fresh()->status);
    }

    public function test_batch_yang_belum_dikonfirmasi_404_tetap_tiba_labkesda(): void
    {
        $batch = $this->makeBatch(456);
        Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'not found'], 404)]);

        $this->artisan('produli:poll-prolanis-delivery-confirmation')->assertSuccessful();

        $this->assertSame('tiba_labkesda', $batch->fresh()->status);
    }

    public function test_batch_tanpa_silakes_batch_ref_dilewati_tanpa_panggilan_http(): void
    {
        $this->makeBatch(null);
        Http::fake();

        $this->artisan('produli:poll-prolanis-delivery-confirmation')->assertSuccessful();

        Http::assertNothingSent();
    }
}
