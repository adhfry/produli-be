<?php

namespace App\Services\Notification\Channels;

use App\Models\User;
use App\Services\Notification\NotificationPayload;
use App\Services\Notification\ReminderChannel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Channel 'wa' -- bot WhatsApp milik sendiri (revisi Bu Kadis), kontrak API sudah fix dari user:
 * POST {WA_API_BASE_URL}/send/chat-message/{WA_API_KEY} body {phone, message, link_image}.
 *
 * PENTING: config (base_url/api_key) SENGAJA dibaca di dalam send(), BUKAN di constructor --
 * kalau constructor throw saat config kosong (pola SilakesApiClient), SIAPA PUN yang resolve
 * class ini lewat container (mis. NotificationService/NotifyService, atau test) bakal ikut
 * gagal duluan sebelum WA_API_BASE_URL/WA_API_KEY diisi -- kelas bug yang sama seperti FcmService
 * (lihat docblock di sana). send() sendiri throw kalau config kosong ATAU request gagal --
 * pemanggil (NotifyService) yang tentukan apakah itu boleh graceful-degrade atau tidak.
 */
class WhatsappReminderChannel implements ReminderChannel
{
    public function key(): string
    {
        return 'wa';
    }

    public function send(User $notifiable, NotificationPayload $payload): void
    {
        $baseUrl = (string) config('produli.whatsapp.base_url');
        $apiKey = (string) config('produli.whatsapp.api_key');

        if ($baseUrl === '' || $apiKey === '') {
            throw new RuntimeException('Konfigurasi WA_API_BASE_URL/WA_API_KEY belum diisi di .env.');
        }

        $phone = $this->normalizePhone($this->resolvePhoneNumber($notifiable));

        if ($phone === null) {
            throw new RuntimeException("User #{$notifiable->id} tidak punya nomor WA/HP yang bisa dipakai.");
        }

        $response = Http::baseUrl(rtrim($baseUrl, '/'))
            ->acceptJson()
            ->timeout(15)
            ->retry(3, fn (int $attempt) => $attempt * 1000, function (Throwable $e) {
                return $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->status() >= 500);
            }, throw: false)
            ->post("/send/chat-message/{$apiKey}", [
                'phone' => $phone,
                'message' => $payload->body !== '' ? "{$payload->title}\n{$payload->body}" : $payload->title,
                'link_image' => $payload->imageUrl,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf('WA API gagal (HTTP %d): %s', $response->status(), $response->body()));
        }
    }

    /**
     * User staf (admin_puskesmas/pj_prolanis) simpan no_wa/no_hp langsung di kolom users, kader
     * dan tenaga_kesehatan di tabel profil masing-masing.
     */
    private function resolvePhoneNumber(User $notifiable): ?string
    {
        return $notifiable->kader?->no_wa
            ?? $notifiable->kader?->no_hp
            ?? $notifiable->tenagaKesehatan?->no_wa
            ?? $notifiable->tenagaKesehatan?->no_hp
            ?? $notifiable->no_wa
            ?? $notifiable->no_hp;
    }

    private function normalizePhone(?string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $raw);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            return '62'.substr($digits, 1);
        }

        if (str_starts_with($digits, '62')) {
            return $digits;
        }

        return '62'.$digits;
    }
}
