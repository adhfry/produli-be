<?php

namespace App\Console\Commands;

use App\Exceptions\SinkronSedangBerjalanException;
use App\Models\IntegrationSyncLog;
use App\Models\RiskTransitionScore;
use App\Services\Performance\RiskTransitionScorer;
use App\Services\Silakes\SyncSilakesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Hitung ulang semua transisi lama dengan aturan poin v2 (blueprint docs/planning/16 §4e,
 * 2026-11-17): hanya transisi karena pemeriksaan lab baru yang dihitung, bonus kunjungan +5.
 *
 * Baris diperbarui DI TEMPAT (tidak dihapus, id tetap). Puskesmas yang tersimpan dipertahankan
 * (pemilik saat skor pertama dihitung); hanya yang kosong diisi pemilik saat ini. Memegang kunci
 * sinkron supaya klasifikasi baru tidak menulis skor di tengah proses. --uji hanya menghitung.
 */
class HitungUlangPoinCommand extends Command
{
    protected $signature = 'produli:hitung-ulang-poin {--uji : Hanya tampilkan dampaknya, tanpa menulis}';

    protected $description = 'Hitung ulang poin transisi risiko dengan aturan v2 (semua perbaikan karena lab baru + bonus kunjungan)';

    public function handle(RiskTransitionScorer $scorer): int
    {
        $uji = (bool) $this->option('uji');
        $kunci = Cache::lock(SyncSilakesService::KUNCI, SyncSilakesService::KUNCI_TTL_DETIK);
        if (! $kunci->get()) {
            $this->warn((new SinkronSedangBerjalanException)->getMessage().' Tidak ada yang diubah.');

            return self::FAILURE;
        }

        $sebelum = $this->ringkasan();
        $berubah = 0;
        $dilewati = 0;

        try {
            RiskTransitionScore::query()
                ->with(['patient', 'previousRiskClassification', 'currentRiskClassification'])
                ->chunkById(500, function ($baris) use ($scorer, $uji, &$berubah, &$dilewati) {
                    DB::transaction(function () use ($baris, $scorer, $uji, &$berubah, &$dilewati) {
                        foreach ($baris as $skor) {
                            if (! $skor->patient || ! $skor->previousRiskClassification || ! $skor->currentRiskClassification) {
                                $dilewati++;

                                continue;
                            }
                            $nilai = $scorer->hitung($skor->patient, $skor->previousRiskClassification, $skor->currentRiskClassification,
                                $skor->puskesmas_id ?? $skor->patient->puskesmas_id);
                            $skor->fill($nilai);
                            if ($skor->isDirty()) {
                                $berubah++;
                                if (! $uji) {
                                    $skor->save();
                                }
                            }
                        }
                    });
                });
        } finally {
            $kunci->release();
        }

        $sesudah = $uji ? null : $this->ringkasan();
        if (! $uji) {
            IntegrationSyncLog::create([
                'service_name' => 'HitungUlangPoinCommand',
                'endpoint' => 'risk_transition_scores',
                'requested_at' => now(),
                'status' => 'success',
                'records_count' => $berubah,
                'details' => ['sebelum' => $sebelum, 'sesudah' => $sesudah, 'dilewati' => $dilewati],
            ]);
        }

        $this->info(($uji ? '[UJI, tidak ada yang ditulis] ' : '').json_encode([
            'baris_berubah' => $berubah, 'dilewati_data_tidak_lengkap' => $dilewati, 'sebelum' => $sebelum, 'sesudah' => $sesudah,
        ]));

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function ringkasan(): array
    {
        $r = RiskTransitionScore::query()->selectRaw(
            'count(*) total, sum(eligible) dihitung, sum(case when eligible then final_point else 0 end) total_poin_dihitung, '
            .'sum(case when eligible and final_point > 0 then 1 else 0 end) transisi_membaik, '
            .'sum(case when eligible and final_point < 0 then 1 else 0 end) transisi_memburuk, '
            .'count(distinct case when eligible then puskesmas_id end) puskesmas_dinilai'
        )->first();

        return array_map('intval', $r->toArray());
    }
}
