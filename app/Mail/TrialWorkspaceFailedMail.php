<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * TRIAL-SIGNUP-QUEUE-1 — the workspace could not be built. The half-made one has been removed, so
 * the same address is free to try again.
 */
class TrialWorkspaceFailedMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $brand,
        public string $businessName,
        public string $tryAgainUrl,
        public string $supportEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Your :brand workspace could not be created', ['brand' => $this->brand]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.trial-workspace-failed');
    }
}
