<?php

namespace App\Console\Commands;

use App\Models\IntegrationSyncLog;
use App\Models\PatientsCache;
use App\Services\Silakes\SilakesApiClient;
use App\Services\Silakes\SyncSilakesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/**
 * Isi kolom pasien yang KOSONG di PRODULI dari SiLAKES (revisi 2026-11-17).
 *
 * Kenapa perlu: sinkron harian hanya menarik pasien yang BERUBAH di SiLAKES. Kolom yang baru
 * ditambahkan ke sinkron (NIK sejak 2026-08-13, no. BPJS sejak 2026-08-17) tidak pernah terisi
 * untuk pasien yang tidak diubah lagi setelah tanggal itu (temuan: 4.992 NIK kosong).
 *
 * Aturan pengaman:
 * - hanya mengisi kolom yang KOSONG; nilai yang sudah ada tidak pernah ditimpa (dicek ulang di
 *   query UPDATE itu sendiri, jadi aman walau event realtime mengisinya di tengah jalan);
 * - NIK hanya diisi bila nik_hash dari SiLAKES SAMA dengan nik_hash yang sudah tersimpan
 *   (bukti baris itu memang orang yang sama);
 * - tidak menyentuh last_synced_at (batas sinkron harian) dan tidak menghitung ulang puskesmas;
 * - memegang kunci yang sama dengan sinkron harian dan tombol manual (tidak berjalan bersamaan);
 * - --uji hanya menghitung, tidak menulis apa pun;
 * - setiap pengisian dicatat ke storage/app/isi-ulang-pasien/*.json untuk audit/pembatalan.
 */
class IsiUlangPasienCommand extends Command
{
    /** Kolom yang boleh diisi: nama di patients_cache => kunci di payload SiLAKES. */
    private const KOLOM = ['nik' => 'nik', 'no_bpjs' => 'no_bpjs', 'no_reg' => 'no_reg', 'phone' => 'phone'];

    private const PER_PAGE = 200;

    private const PAGE_DELAY_MS = 1200;

    protected $signature = 'produli:isi-ulang-pasien
        {--kolom=nik,no_bpjs : Kolom yang diisi bila kosong (nik, no_bpjs, no_reg, phone)}
        {--uji : Hanya hitung, jangan tulis apa pun}';

    protected $description = 'Isi kolom pasien yang kosong di PRODULI dari SiLAKES, tanpa menimpa data yang sudah ada';

    public function handle(SilakesApiClient $client): int
    {
        $kolom = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('kolom')))));
        $tidakDikenal = array_diff($kolom, array_keys(self::KOLOM));
        if ($kolom === [] || $tidakDikenal !== []) {
            $this->error('Kolom tidak dikenal: '.implode(', ', $tidakDikenal ?: ['(kosong)']).'. Pilihan: '.implode(', ', array_keys(self::KOLOM)));

            return self::FAILURE;
        }
        $uji = (bool) $this->option('uji');

        $kunci = Cache::lock(SyncSilakesService::KUNCI, SyncSilakesService::KUNCI_TTL_DETIK);
        if (! $kunci->get()) {
            $this->warn('Sinkronisasi SiLAKES lain sedang berjalan. Tidak ada yang diubah; jalankan lagi nanti.');

            return self::FAILURE;
        }

        $mulai = now();
        $hitung = ['dipindai' => 0, 'ada_di_produli' => 0, 'nik_hash_beda' => 0] + array_fill_keys(array_map(fn ($k) => "diisi_{$k}", $kolom), 0);
        $perubahan = [];

        try {
            $cursor = null;
            do {
                $body = $client->patients(array_filter(['cursor' => $cursor, 'per_page' => self::PER_PAGE]));
                $baris = collect($body['data'] ?? [])->keyBy('patient_id');
                $hitung['dipindai'] += $baris->count();

                $lokal = PatientsCache::whereIn('external_patient_id', $baris->keys())
                    ->get(['id', 'external_patient_id', 'nik_hash', ...$kolom]);
                $hitung['ada_di_produli'] += $lokal->count();

                foreach ($lokal as $p) {
                    $row = $baris[$p->external_patient_id];
                    foreach ($kolom as $k) {
                        $nilai = trim((string) ($row[self::KOLOM[$k]] ?? ''));
                        if ($nilai === '' || trim((string) $p->{$k}) !== '') {
                            continue;
                        }
                        if ($k === 'nik' && ($row['nik_hash'] ?? null) !== $p->nik_hash) {
                            $hitung['nik_hash_beda']++;

                            continue;
                        }

                        if (! $uji) {
                            $terisi = PatientsCache::whereKey($p->id)
                                ->where(fn ($q) => $q->whereNull($k)->orWhere($k, ''))
                                ->update([$k => $nilai]);
                            if ($terisi === 0) {
                                continue; // sudah diisi jalur lain di antara baca dan tulis
                            }
                            $perubahan[] = ['id' => $p->id, 'external_patient_id' => $p->external_patient_id, 'kolom' => $k];
                        }
                        $hitung["diisi_{$k}"]++;
                    }
                }

                $cursor = $body['meta']['next_cursor'] ?? null;
                $lanjut = (bool) ($body['meta']['has_more'] ?? false) && $cursor;
                if ($lanjut) {
                    Sleep::for(self::PAGE_DELAY_MS)->milliseconds();
                }
            } while ($lanjut);
        } finally {
            $kunci->release();
        }

        $berkas = null;
        if (! $uji) {
            $berkas = 'isi-ulang-pasien/'.$mulai->format('Ymd-His').'.json';
            Storage::disk('local')->put($berkas, json_encode([
                'mulai' => $mulai->toIso8601String(), 'selesai' => now()->toIso8601String(),
                'kolom' => $kolom, 'ringkasan' => $hitung, 'perubahan' => $perubahan,
            ], JSON_PRETTY_PRINT));

            IntegrationSyncLog::create([
                'service_name' => 'IsiUlangPasienCommand',
                'endpoint' => 'patients',
                'requested_at' => $mulai,
                'status' => 'success',
                'records_count' => count($perubahan),
                'details' => $hitung + ['kolom' => $kolom, 'berkas' => $berkas],
            ]);
        }

        $this->info(($uji ? '[UJI, tidak ada yang ditulis] ' : '').json_encode($hitung));
        if ($berkas) {
            $this->info("Catatan perubahan: storage/app/{$berkas}");
        }

        return self::SUCCESS;
    }
}
