<?php

namespace App\Notifications;

use App\Models\VisitAssignment;
use Illuminate\Notifications\Notification;

/**
 * Reminder H-1/hari-H kunjungan kader (docs/planning/02 §8). Dikirim SINKRON di dalam
 * SendVisitReminderJob (sudah berjalan async lewat queue) -- TIDAK implements ShouldQueue di
 * sini, supaya tidak double-queue (job di dalam job) dan supaya status reminder (sent/failed)
 * bisa dicatat akurat berdasar hasil pengiriman sebenarnya, bukan cuma "berhasil di-enqueue".
 */
class VisitReminderNotification extends Notification
{
    public function __construct(private readonly VisitAssignment $assignment) {}

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
        return [
            'type' => 'visit_reminder',
            'assignment_id' => $this->assignment->id,
            'patient_nama' => $this->assignment->patient->nama,
            'scheduled_date' => $this->assignment->scheduled_date->toDateString(),
            'priority' => $this->assignment->priority,
        ];
    }
}
