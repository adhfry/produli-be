<?php

namespace App\Services\Notification\Channels;

use App\Models\User;
use App\Notifications\GenericMailNotification;
use App\Services\Notification\NotificationPayload;
use App\Services\Notification\ReminderChannel;
use Illuminate\Support\Facades\Notification;

/**
 * Channel 'email' -- dipakai NotifyService untuk event yang perlu email (mis. update pasien
 * puskesmas-scoped). Menghormati users.email_notifications_enabled (opt-out non-kritis, sama
 * seperti VisitAssignedMail/pola existing) -- silent no-op kalau user matikan.
 */
class EmailReminderChannel implements ReminderChannel
{
    public function key(): string
    {
        return 'email';
    }

    public function send(User $notifiable, NotificationPayload $payload): void
    {
        if (! $notifiable->email_notifications_enabled) {
            return;
        }

        Notification::send($notifiable, new GenericMailNotification($payload));
    }
}
