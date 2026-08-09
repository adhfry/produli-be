<?php

namespace App\Services\Notification\Channels;

use App\Models\Kader;
use App\Models\VisitAssignment;
use App\Notifications\VisitReminderNotification;
use App\Services\Notification\ReminderChannel;
use Illuminate\Support\Facades\Notification;

/**
 * Placeholder utk Web Push browser asli (docs/planning/02 §8) -- pakai tabel `notifications`
 * bawaan Laravel (channel 'database') sebagai channel 'push' yang BENAR-BENAR berfungsi
 * sekarang (PWA kader bisa poll daftar notifikasinya), sampai infrastruktur Web Push nyata
 * (VAPID key, tabel push_subscriptions, service worker PWA) dibangun sebagai task terpisah.
 */
class DatabaseReminderChannel implements ReminderChannel
{
    public function key(): string
    {
        return 'push';
    }

    public function send(Kader $kader, VisitAssignment $assignment): void
    {
        Notification::send($kader->user, new VisitReminderNotification($assignment));
    }
}
