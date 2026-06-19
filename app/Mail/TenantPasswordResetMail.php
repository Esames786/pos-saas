<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Password-reset link for a tenant user (PRD-5). The reset URL is built on the
 * tenant's own subdomain by Tenant\User::sendPasswordResetNotification().
 */
class TenantPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $brand,
        public string $resetUrl,
        public int $expireMinutes,
        public string $supportEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your ' . $this->brand . ' password');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.tenant-password-reset');
    }
}
