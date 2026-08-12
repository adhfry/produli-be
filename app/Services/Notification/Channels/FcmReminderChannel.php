<?php

namespace App\Services\Notification\Channels;

use App\Models\User;
use App\Services\Notification\FcmService;
use App\Services\Notification\NotificationPayload;
use App\Services\Notification\ReminderChannel;

/**
 * Channel 'fcm' -- push notification browser (Firebase Cloud Messaging, lihat FcmService).
 * SENGAJA tidak pernah throw (FcmService::sendToUser() sudah graceful -- 0 token terdaftar,
 * config belum diisi, atau gagal kirim ke Firebase semuanya cuma di-log lalu return 0, tidak
 * pernah exception) -- konsisten dengan filosofi "FCM opsional, jangan pernah gagalkan alur
 * lain gara-gara push browser tidak sampai" yang sudah dipakai FcmService sejak awal.
 */
class FcmReminderChannel implements ReminderChannel
{
    public function __construct(private readonly FcmService $fcmService) {}

    public function key(): string
    {
        return 'fcm';
    }

    public function send(User $notifiable, NotificationPayload $payload): void
    {
        $this->fcmService->sendToUser($notifiable, $payload->title, $payload->body, $payload->data);
    }
}
