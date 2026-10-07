<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * TRIAL-SIGNUP-QUEUE-1 — sent the moment a trial signup is accepted, before the workspace is built.
 *
 * Plain strings only: it is queued, and nothing in it needs a tenant to exist yet.
 */
class TrialWorkspacePreparingMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $brand,
        public string $businessName,
        public string $workspaceAddress,
        public string $ownerEmail,
        public string $supportEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'We are setting up your ' . $this->brand . ' workspace');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.trial-workspace-preparing');
    }
}
