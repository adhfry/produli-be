<?php

namespace App\Notifications;

use App\Services\Notification\NotificationPayload;
use Illuminate\Notifications\Notification;

/**
 * Notification generik untuk channel database/push -- dipakai DatabaseReminderChannel untuk
 * SEMUA jenis event (reminder kunjungan, sync SiLAKES selesai, update pasien, kunjungan
 * mendesak), bukan satu class per jenis event seperti VisitReminderNotification (sekarang
 * tidak lagi dipakai jalur pengiriman, dipertahankan filenya karena masih dirujuk sebagai
 * fixture type-string di beberapa test). toDatabase() SENGAJA cuma `return $payload->data`,
 * bukan gabungan dengan title/body -- menjaga shape `notifications.data` persis sama dengan
 * yang sudah dites (mis. `data['assignment_id']`), NotificationResource yang urus title/body
 * tampilan dari `data['type']`.
 */
class GenericDatabaseNotification extends Notification
{
    public function __construct(private readonly NotificationPayload $payload) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload->data;
    }
}
