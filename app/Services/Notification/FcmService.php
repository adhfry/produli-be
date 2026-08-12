<?php

namespace App\Services\Notification;

use App\Models\FcmToken;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\Notification as FcmNotification;
use Throwable;

/**
 * Kirim push notification lewat Firebase Cloud Messaging (web push PWA). Butuh
 * FIREBASE_CREDENTIALS (service account JSON) terisi di .env -- kalau belum, send()/
 * sendToUser() cuma log warning + tidak melempar exception (supaya pemanggil, mis. NotifyService
 * nanti di Fase 2, tidak perlu tahu FCM sudah dikonfigurasi atau belum -- graceful degrade sama
 * seperti channel lain yang belum lengkap konfigurasinya).
 *
 * PENTING: `Kreait\Firebase\Contract\Messaging` SENGAJA TIDAK di-inject lewat constructor --
 * resolve container-nya langsung throw RuntimeException ("Unable to determine the Firebase
 * Project ID") begitu FIREBASE_CREDENTIALS kosong, walau belum ada yang benar-benar minta
 * kirim apa pun. Kalau di-inject lewat constructor, SIAPA PUN yang resolve FcmService lewat
 * container (mis. test, atau service lain yang nanti depend ke FcmService) bakal ikut gagal
 * duluan sebelum .env diisi -- kelas bug yang sama seperti WhatsappReminderChannel (lihat plan
 * Fase 2). Messaging baru di-resolve lazy di dalam method, setelah isConfigured() lolos DAN
 * dibungkus try/catch (jaga-jaga kalau JSON-nya ada tapi rusak/tidak valid).
 *
 * Registrasi token: satu user bisa punya banyak token aktif (banyak device/browser). Kirim ke
 * SEMUA token user itu sekaligus lewat sendMulticast() -- token yang sudah tidak valid (uninstall/
 * browser data cleared) otomatis dihapus dari fcm_tokens berdasarkan MulticastSendReport.
 */
class FcmService
{
    public function isConfigured(): bool
    {
        return filled(config('firebase.projects.app.credentials'));
    }

    /**
     * @return int  jumlah token yang berhasil dikirimi
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): int
    {
        $tokens = $user->fcmTokens()->pluck('token', 'id');

        if ($tokens->isEmpty()) {
            return 0;
        }

        return $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>  $tokensById  keyed by fcm_tokens.id, supaya token invalid bisa dihapus tepat sasaran
     */
    private function sendToTokens($tokensById, string $title, string $body, array $data = []): int
    {
        if (! $this->isConfigured()) {
            Log::warning('FcmService: FIREBASE_CREDENTIALS belum diisi, push notification dilewati', [
                'title' => $title,
            ]);

            return 0;
        }

        $message = CloudMessage::new()
            ->withNotification(FcmNotification::create($title, $body))
            ->withData(array_map('strval', $data));

        try {
            $messaging = app(Messaging::class);
            $report = $messaging->sendMulticast($message, array_values($tokensById->all()));
        } catch (Throwable $e) {
            Log::warning('FcmService: gagal kirim multicast', ['error' => $e->getMessage()]);

            return 0;
        }

        $this->pruneInvalidTokens($tokensById, $report);

        return $report->successes()->count();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>  $tokensById
     */
    private function pruneInvalidTokens($tokensById, MulticastSendReport $report): void
    {
        foreach ($report->invalidTokens() as $invalidToken) {
            $id = $tokensById->search($invalidToken);

            if ($id !== false) {
                FcmToken::whereKey($id)->delete();
            }
        }
    }
}
