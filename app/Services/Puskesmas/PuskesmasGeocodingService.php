<?php

namespace App\Services\Puskesmas;

use App\Models\Puskesmas;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cari-otomatis titik koordinat SELURUH puskesmas se-Kabupaten Sumenep (permintaan Bu Kadis,
 * super_admin only, lihat PuskesmasPolicy::geocodeAll) -- pakai OpenStreetMap Nominatim (gratis,
 * tanpa API key), BUKAN Google Geocoding/Places -- proyek ini sengaja tidak pakai Google Maps
 * sama sekali (lihat NUXT_PUBLIC_TILE_SERVER_URL, peta pakai MapLibre GL self-hosted).
 *
 * Kebijakan pemakaian Nominatim (operations.osmfoundation.org/policies/nominatim) MEWAJIBKAN:
 * maksimal 1 request/detik, DAN header User-Agent yang mengidentifikasi aplikasi -- pelanggaran
 * bisa berujung IP diblokir. Delay antar request DISENGAJA sedikit di atas 1 detik (bukan pas
 * 1000ms) untuk margin aman, bukan dipas-paskan ke batas.
 */
class PuskesmasGeocodingService
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    /**
     * @return array{total: int, updated: int, skipped: int, failed: int, details: array<int, array{puskesmas_id: int, nama: string, status: string, latitude?: float, longitude?: float, reason?: string}>}
     */
    public function geocodeAll(bool $overwriteExisting = false): array
    {
        // 31 puskesmas x ~1,1 detik/request bisa >30 detik -- lebih dari max_execution_time
        // default banyak konfigurasi PHP kalau tidak dinaikkan eksplisit di sini.
        set_time_limit(180);

        $totalCount = Puskesmas::count();

        $query = Puskesmas::query()->orderBy('nama');
        if (! $overwriteExisting) {
            // Default: JANGAN timpa puskesmas yang staf/super_admin sudah pernah tandai/koreksi
            // manual -- cuma isi yang benar-benar masih kosong. overwriteExisting=true (opt-in
            // dari UI) kalau memang ingin cari ulang semuanya dari nol.
            $query->where(function ($q) {
                $q->whereNull('latitude')->orWhereNull('longitude');
            });
        }
        $puskesmasList = $query->get();

        $delayMicroseconds = (int) config('produli.geocoding.rate_limit_delay_microseconds');

        $details = [];
        $updated = 0;
        $failed = 0;

        foreach ($puskesmasList as $index => $puskesmas) {
            if ($index > 0 && $delayMicroseconds > 0) {
                usleep($delayMicroseconds);
            }

            $result = $this->geocodeOne($puskesmas);
            $details[] = $result;

            if ($result['status'] === 'updated') {
                $updated++;
            } elseif ($result['status'] === 'failed') {
                $failed++;
            }
        }

        return [
            'total' => $totalCount,
            'updated' => $updated,
            'skipped' => $totalCount - $puskesmasList->count(),
            'failed' => $failed,
            'details' => $details,
        ];
    }

    /**
     * @return array{puskesmas_id: int, nama: string, status: string, latitude?: float, longitude?: float, reason?: string}
     */
    private function geocodeOne(Puskesmas $puskesmas): array
    {
        $queryParts = array_filter([$puskesmas->nama, $puskesmas->alamat, 'Kabupaten Sumenep', 'Jawa Timur', 'Indonesia']);
        $searchQuery = implode(', ', $queryParts);

        try {
            $response = Http::withHeaders([
                // WAJIB per kebijakan Nominatim -- request tanpa User-Agent yang jelas bisa
                // ditolak/diblokir tanpa peringatan dulu.
                'User-Agent' => 'PRODULI-Sumenep/1.0 (Dinas Kesehatan Kabupaten Sumenep; produli@labkesdasumenep.id)',
            ])->timeout(15)->get(self::ENDPOINT, [
                'q' => $searchQuery,
                'format' => 'json',
                'limit' => 1,
                'countrycodes' => 'id',
            ]);

            if (! $response->successful()) {
                return $this->failure($puskesmas, 'Nominatim membalas error (HTTP '.$response->status().')');
            }

            $results = $response->json();
            if (! is_array($results) || count($results) === 0) {
                return $this->failure($puskesmas, "Tidak ditemukan hasil untuk \"{$searchQuery}\"");
            }

            $latitude = (float) $results[0]['lat'];
            $longitude = (float) $results[0]['lon'];

            $puskesmas->update(['latitude' => $latitude, 'longitude' => $longitude]);

            return [
                'puskesmas_id' => $puskesmas->id,
                'nama' => $puskesmas->nama,
                'status' => 'updated',
                'latitude' => $latitude,
                'longitude' => $longitude,
            ];
        } catch (Throwable $e) {
            Log::warning('Geocoding puskesmas via Nominatim gagal', [
                'puskesmas_id' => $puskesmas->id,
                'error' => $e->getMessage(),
            ]);

            return $this->failure($puskesmas, 'Kesalahan teknis: '.$e->getMessage());
        }
    }

    /**
     * @return array{puskesmas_id: int, nama: string, status: string, reason: string}
     */
    private function failure(Puskesmas $puskesmas, string $reason): array
    {
        return [
            'puskesmas_id' => $puskesmas->id,
            'nama' => $puskesmas->nama,
            'status' => 'failed',
            'reason' => $reason,
        ];
    }
}
