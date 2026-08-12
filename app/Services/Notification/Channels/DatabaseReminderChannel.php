<?php

namespace App\Services\Notification\Channels;

use App\Models\User;
use App\Notifications\GenericDatabaseNotification;
use App\Services\Notification\NotificationPayload;
use App\Services\Notification\ReminderChannel;
use Illuminate\Support\Facades\Notification;

/**
 * Channel 'push' -- pakai tabel `notifications` bawaan Laravel (docs/planning/02 §8), BENAR-BENAR
 * berfungsi sekarang (PWA kader/staf bisa poll daftar notifikasinya), sampai infrastruktur Web
 * Push browser asli (VAPID/service worker) dibangun -- FCM (lihat FcmService) sudah jalan
 * terpisah untuk push browser, channel ini tetap dipakai untuk notifikasi IN-APP (bell icon).
 */
class DatabaseReminderChannel implements ReminderChannel
{
    public function key(): string
    {
        return 'push';
    }

    public function send(User $notifiable, NotificationPayload $payload): void
    {
        Notification::send($notifiable, new GenericDatabaseNotification($payload));
    }
}
