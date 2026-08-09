<?php

namespace App\Notifications;

use App\Models\VisitReport;
use Illuminate\Notifications\Notification;

/**
 * Laporan kunjungan dinyatakan TIDAK VALID oleh super_admin (docs/planning/02 §11) -- kader
 * perlu tahu alasannya (validation_note) supaya bisa kunjungan ulang dengan benar. Assignment
 * terkait sudah dikembalikan ke status pending SEBELUM notifikasi ini dikirim
 * (VisitReportReviewService::validateReport()), jadi ini murni pemberitahuan, bukan aksi.
 */
class VisitReportInvalidatedNotification extends Notification
{
    public function __construct(private readonly VisitReport $report) {}

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
            'type' => 'visit_report_invalidated',
            'visit_report_id' => $this->report->id,
            'assignment_id' => $this->report->assignment_id,
            'patient_nama' => $this->report->assignment->patient->nama,
            'validation_note' => $this->report->validation_note,
        ];
    }
}
