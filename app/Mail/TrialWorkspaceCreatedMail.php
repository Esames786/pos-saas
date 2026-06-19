<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the owner after a public trial workspace is provisioned (PRD-5).
 *
 * Note: self-signup uses an owner-chosen password (never generated/stored in
 * plaintext), so this email intentionally does NOT contain a password — it
 * points the owner to the login URL and the password-reset flow instead.
 */
class TrialWorkspaceCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $brand,
        public string $businessName,
        public string $loginUrl,
        public string $ownerEmail,
        public ?string $trialEnds,
        public string $supportEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->brand . ' workspace is ready');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.trial-workspace-created');
    }
}
