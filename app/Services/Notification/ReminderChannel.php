<?php

namespace App\Services\Notification;

use App\Models\User;

/**
 * Strategy per channel pengiriman notifikasi (docs/planning/02 §8, diperluas revisi Bu Kadis) --
 * pola sama seperti VisitValidationLayer (Open/Closed: tambah channel baru tanpa ubah
 * NotificationService/NotifyService). `key()` HARUS cocok dengan nilai kolom reminders.channel
 * (untuk reminder terjadwal) ATAU key yang dipakai NotifyService::notify() (untuk event lain).
 *
 * Signature send(User, NotificationPayload) SENGAJA generik (bukan Kader+VisitAssignment
 * seperti versi awal) -- supaya channel yang sama dipakai baik untuk reminder kunjungan MAUPUN
 * event baru (sync SiLAKES selesai, update pasien, kunjungan mendesak) yang tidak selalu
 * berhubungan dengan satu VisitAssignment tertentu.
 */
interface ReminderChannel
{
    public function key(): string;

    public function send(User $notifiable, NotificationPayload $payload): void;
}
