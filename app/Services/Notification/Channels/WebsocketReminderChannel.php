<?php

namespace App\Services\Notification\Channels;

use App\Models\User;
use App\Services\Notification\NotificationPayload;
use App\Services\Notification\ReminderChannel;
use App\Services\Realtime\RealtimeBroadcastService;

/**
 * Channel 'ws' -- sinyal realtime ke bel notifikasi user lewat produli-wss, topic "user:{id}"
 * (tiap user otomatis join topic ini sendiri saat socket connect, lihat useRealtime.ts).
 * Payload SENGAJA cuma `type` (bukan title/body/data penuh) -- frontend yang terima event
 * "notification.new" ini melakukan refetch senyap ke GET /notifications yang sudah ada
 * (loadNotifications() di useNotifications.ts), bukan merender langsung dari sini. Lihat
 * docblock RealtimeBroadcastService untuk alasan "sinyal, bukan transport data".
 */
class WebsocketReminderChannel implements ReminderChannel
{
    public function __construct(private readonly RealtimeBroadcastService $realtime) {}

    public function key(): string
    {
        return 'ws';
    }

    public function send(User $notifiable, NotificationPayload $payload): void
    {
        $this->realtime->broadcast("user:{$notifiable->id}", 'notification.new', [
            'type' => $payload->type,
        ]);
    }
}
