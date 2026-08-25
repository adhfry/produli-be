<?php

namespace App\Services\Realtime;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Satu-satunya jalur publish event ke produli-wss (Phoenix, POST /internal/broadcast) --
 * dipakai langsung untuk sinyal realtime dashboard (mis. "puskesmas:5" -> visit_report.submitted)
 * DAN dipakai WebsocketReminderChannel di dalam untuk sinyal bel notifikasi per-user
 * ("user:{id}" -> notification.new). Payload SENGAJA ringan (sinyal "ada perubahan", bukan
 * transport data penuh) -- frontend yang menerima event ini melakukan refetch senyap
 * (stale-while-revalidate, TANPA skeleton) lewat endpoint REST yang sudah ada, bukan
 * merender langsung dari payload broadcast. Menghindari 2 sumber kebenaran shape data
 * (REST response vs payload websocket) yang gampang divergen seiring waktu.
 *
 * Config (base_url/broadcast_secret) SENGAJA dibaca di dalam broadcast(), bukan di
 * constructor -- pola sama seperti WhatsappReminderChannel/SilakesApiClient (lihat
 * docblock keduanya): supaya resolve class ini lewat container tidak ikut gagal duluan
 * cuma karena env belum diisi di suatu environment.
 */
class RealtimeBroadcastService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function broadcast(string $topic, string $event, array $payload = []): void
    {
        $baseUrl = (string) config('produli.realtime.base_url');
        $secret = (string) config('produli.realtime.broadcast_secret');

        if ($baseUrl === '' || $secret === '') {
            throw new RuntimeException('Konfigurasi PRODULI_WSS_BASE_URL/WSS_BROADCAST_SECRET belum diisi di .env.');
        }

        $response = Http::baseUrl(rtrim($baseUrl, '/'))
            ->acceptJson()
            ->timeout(5)
            ->retry(2, fn (int $attempt) => $attempt * 300, function (Throwable $e) {
                return $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->status() >= 500);
            }, throw: false)
            ->withHeaders(['x-internal-secret' => $secret])
            ->post('/internal/broadcast', [
                'topic' => $topic,
                'event' => $event,
                'payload' => $payload,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf('produli-wss broadcast gagal (HTTP %d): %s', $response->status(), $response->body()));
        }
    }
}
