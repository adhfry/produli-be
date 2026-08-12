<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email aktivasi akun untuk staf (admin_puskesmas/pj_prolanis) & kader baru. ShouldQueue supaya
 * endpoint registrasi (POST /api/v1/staff, /api/v1/kader) tidak menunggu SMTP selesai kirim.
 */
class AccountActivationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $activationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Aktivasi Akun PRODULI',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.account-activation',
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
