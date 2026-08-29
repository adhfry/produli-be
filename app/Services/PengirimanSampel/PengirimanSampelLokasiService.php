<?php

namespace App\Services\PengirimanSampel;

use App\Models\PengirimanSampel;
use App\Models\PengirimanSampelLokasi;
use App\Services\Realtime\RealtimeBroadcastService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Heartbeat GPS kurir yang sedang OTW (Fase C) -- lihat docblock migrasi
 * create_pengiriman_sampel_lokasi_table utk kenapa cuma 1 baris terkini per batch (bukan log).
 * Terpisah dari PengirimanSampelService (bukan bagian state-machine transisi status) karena
 * dipanggil jauh lebih sering (tiap ~20-30 detik) dan tidak pernah mengubah `pengiriman_sampel.
 * status` -- pemisahan ini juga memudahkan kalau nanti perlu rate-limit/behavior khusus endpoint
 * heartbeat tanpa menyentuh transisi status lain.
 */
class PengirimanSampelLokasiService
{
    public function __construct(private readonly RealtimeBroadcastService $realtimeBroadcast) {}

    public function recordHeartbeat(PengirimanSampel $batch, float $lat, float $lng, ?float $accuracy): PengirimanSampelLokasi
    {
        if ($batch->status !== 'otw') {
            throw ValidationException::withMessages([
                'status' => ['Heartbeat lokasi cuma berlaku selama status OTW.'],
            ]);
        }

        $lokasi = PengirimanSampelLokasi::updateOrCreate(
            ['pengiriman_sampel_id' => $batch->id],
            ['latitude' => $lat, 'longitude' => $lng, 'accuracy' => $accuracy, 'recorded_at' => now()],
        );

        // Sinyal SAJA (bukan koordinat itu sendiri di payload) -- keputusan desain eksplisit,
        // konsisten dengan seluruh broadcast realtime lain di codebase ini. Peta super_admin
        // fetch REST GET /pengiriman-sampel/{id}/lokasi setelah menerima sinyal ini.
        try {
            $payload = ['pengiriman_sampel_id' => $batch->id];
            $this->realtimeBroadcast->broadcast('role:super_admin', 'sampel.lokasi_berubah', $payload);
            $this->realtimeBroadcast->broadcast("puskesmas:{$batch->puskesmas_id}", 'sampel.lokasi_berubah', $payload);
        } catch (Throwable $e) {
            Log::warning('PengirimanSampelLokasiService::recordHeartbeat gagal broadcast sinyal', [
                'pengiriman_sampel_id' => $batch->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $lokasi;
    }

    public function latest(PengirimanSampel $batch): ?PengirimanSampelLokasi
    {
        return $batch->lokasi;
    }
}
