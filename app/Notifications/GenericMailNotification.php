<?php

namespace App\Notifications;

use App\Services\Notification\NotificationPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification email generik untuk event NotifyService (sync SiLAKES selesai, update pasien,
 * dst) -- pakai komponen mail default Laravel (mail::message, sudah termasuk header logo yang
 * sama dengan email lain, lihat resources/views/vendor/mail/html/header.blade.php), tidak perlu
 * Blade template khusus per event seperti VisitAssignedMail/AccountActivationMail.
 */
class GenericMailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly NotificationPayload $payload) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject($this->payload->title)
            ->line($this->payload->body);
    }
}
