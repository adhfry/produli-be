<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email berisi password BARU (plaintext, satu-satunya kesempatan lihat -- hanya hash yang
 * tersimpan) setelah admin (super_admin) mereset password user lewat AdminPasswordResetService.
 * ShouldQueue -- pola sama seperti AccountActivationMail, endpoint reset tidak menunggu SMTP.
 */
class AdminPasswordResetMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $newPassword,
        public readonly string $resetByName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Password Akun PRODULI Anda Telah Direset',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.admin-password-reset',
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
