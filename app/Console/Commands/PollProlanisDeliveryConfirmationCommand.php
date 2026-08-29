<?php

namespace App\Console\Commands;

use App\Exceptions\SilakesApiException;
use App\Models\PengirimanSampel;
use App\Services\PengirimanSampel\PengirimanSampelService;
use App\Services\Silakes\SilakesApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Modul "Kirim Data Prolanis ke Labkesda Sumenep", Fase D (REVISI 2026-08-29) -- PRODULI selalu
 * yang memanggil SiLAKES (arah kepercayaan konsisten di seluruh sistem, lihat docblock
 * SilakesApiClient), bukan sebaliknya (webhook SiLAKES->PRODULI TIDAK ADA presedennya sama
 * sekali di codebase ini). Poling batch berstatus 'tiba_labkesda' yang SUDAH sukses terkirim
 * (`silakes_batch_ref` terisi) tapi BELUM dikonfirmasi Labkesda -- 404 dari SiLAKES (worksheet
 * belum sungguh ada, mis. batch isinya semua usulan pasien baru yang belum ada satu pun
 * disetujui staf) BUKAN error, cuma berarti "belum, coba lagi nanti".
 *
 * "Dikonfirmasi" = `worksheet_status === 'disetujui'` (dikonfirmasi user 2026-08-29) -- BUKAN
 * status buatan modul ini sendiri lagi, tapi status ASLI Worksheet SiLAKES yang sudah ada
 * (draf -> diajukan -> disetujui/ditolak/revisi_diajukan -> selesai). Baru dianggap
 * "dikonfirmasi" begitu admin Labkesda BENAR-BENAR menyetujui hasil pemeriksaan lewat halaman
 * Worksheet Prolanis mereka, bukan sekadar diterima sistem.
 */
class PollProlanisDeliveryConfirmationCommand extends Command
{
    protected $signature = 'produli:poll-prolanis-delivery-confirmation';

    protected $description = 'Cek status konfirmasi Labkesda untuk batch pengiriman sampel Prolanis yang sudah tiba';

    public function handle(SilakesApiClient $client, PengirimanSampelService $service): int
    {
        $batches = PengirimanSampel::where('status', 'tiba_labkesda')
            ->whereNotNull('silakes_batch_ref')
            ->get();

        $confirmed = 0;

        foreach ($batches as $batch) {
            try {
                $result = $client->getProlanisDeliveryStatus($batch->id);
            } catch (SilakesApiException $e) {
                if ($e->statusCode !== 404) {
                    Log::warning('Gagal cek status konfirmasi Labkesda', [
                        'pengiriman_sampel_id' => $batch->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                continue; // 404 = belum diproses staf sama sekali, bukan error -- coba lagi run berikutnya.
            }

            $worksheetStatus = $result['data']['worksheet_status'] ?? null;
            if ($worksheetStatus === 'disetujui') {
                $service->markConfirmedBySilakes($batch, null);
                $confirmed++;
            }
        }

        $this->info("{$confirmed} batch dikonfirmasi selesai dari {$batches->count()} yang dipoling.");

        return self::SUCCESS;
    }
}
