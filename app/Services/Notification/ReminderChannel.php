<?php

namespace App\Services\Notification;

use App\Models\Kader;
use App\Models\VisitAssignment;

/**
 * Strategy per channel pengiriman reminder (docs/planning/02 §8) -- pola sama seperti
 * VisitValidationLayer (Open/Closed: tambah channel baru tanpa ubah NotificationService).
 * `key()` HARUS cocok dengan nilai kolom reminders.channel yang mau ditangani channel ini.
 */
interface ReminderChannel
{
    public function key(): string;

    public function send(Kader $kader, VisitAssignment $assignment): void;
}
