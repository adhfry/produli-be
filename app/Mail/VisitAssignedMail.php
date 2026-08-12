<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notifikasi ringkas kunjungan berombongan (docs/planning/02 §16) -- dikirim SETELAH
 * POST /api/v1/visit-assignments/bulk berhasil buat minimal 1 assignment, 1 email PER KADER
 * (primer maupun pendamping yang kena batch itu), BUKAN 1 email per pasien (hindari spam kalau
 * PJ tugaskan banyak pasien sekaligus). Isi SENGAJA minimal, TIDAK sebut nama/data pasien --
 * data kesehatan tidak masuk kanal email yang kurang terjamin dibanding aplikasi. ShouldQueue
 * supaya POST .../bulk tidak menunggu SMTP selesai (pola sama seperti AccountActivationMail).
 */
class VisitAssignedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly int $taskCount,
        public readonly string $scheduledDate,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tugas Kunjungan Baru - PRODULI',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.visit-assigned',
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
