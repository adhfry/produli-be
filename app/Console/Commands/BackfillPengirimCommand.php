<?php

namespace App\Console\Commands;

use App\Models\LabResultCache;
use App\Services\Silakes\SilakesApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

/**
 * One-off backfill lab_results_cache.pengirim (revisi Bu Kadis, Fase 5) untuk baris yang SUDAH
 * tersinkron SEBELUM kolom ini ada -- SyncSilakesService::syncLabResults() pakai delta cursor
 * (MAX(synced_at)), jadi baris lama yang datanya di SiLAKES tidak berubah lagi TIDAK PERNAH
 * tertangkap ulang oleh sync terjadwal biasa.
 *
 * SENGAJA tidak reuse SyncSilakesService::syncLabResults() penuh -- itu juga memicu
 * RiskClassificationService::classify() untuk SETIAP pasien yang "punya data baru" (di sini
 * berarti SEMUA pasien, karena kita menarik ulang seluruh riwayat tanpa filter 'since'), yang
 * akan menulis ribuan baris risk_classifications duplikat tanpa perubahan level sungguhan.
 * Command ini murni menulis kolom pengirim, tidak menyentuh klasifikasi risiko sama sekali.
 *
 * Jalankan SEKALI setelah kolom pengirim ditambahkan, lalu `php artisan produli:reresolve-
 * wilayah` untuk memakai data yang baru terisi ini pada resolusi puskesmas.
 */
class BackfillPengirimCommand extends Command
{
    protected $signature = 'produli:backfill-pengirim';

    protected $description = 'Backfill lab_results_cache.pengirim dari SiLAKES untuk baris yang sudah tersinkron sebelum kolom ini ada';

    private const PER_PAGE = 200;

    private const PAGE_DELAY_MS = 1200;

    public function handle(SilakesApiClient $client): int
    {
        $cursor = null;
        $updated = 0;
        $seen = 0;
        $page = 0;

        do {
            $body = $client->labResults(array_filter([
                'cursor' => $cursor,
                'per_page' => self::PER_PAGE,
            ]));

            $page++;

            foreach ($body['data'] ?? [] as $labResult) {
                $seen++;
                $pengirim = $labResult['pengirim'] ?? null;

                if ($pengirim === null) {
                    continue;
                }

                $updated += LabResultCache::where('external_id', $labResult['lab_result_id'])
                    ->whereNull('pengirim')
                    ->update(['pengirim' => $pengirim]);
            }

            $cursor = $body['meta']['next_cursor'] ?? null;
            $hasMore = (bool) ($body['meta']['has_more'] ?? false);

            $this->line("Halaman {$page}: {$seen} surat_hasil_labs diperiksa, {$updated} baris lab_results_cache diperbarui sejauh ini.");

            if ($hasMore && $cursor) {
                Sleep::for(self::PAGE_DELAY_MS)->milliseconds();
            }
        } while ($hasMore && $cursor);

        $this->info("Selesai: {$seen} surat_hasil_labs diperiksa, {$updated} baris lab_results_cache diperbarui dengan pengirim.");

        return self::SUCCESS;
    }
}
