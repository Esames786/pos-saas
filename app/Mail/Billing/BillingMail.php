<?php

namespace App\Mail\Billing;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * CLOUD-BILLING-3A — base for every transactional billing email.
 *
 * Provider-neutral (plain Laravel Mailable, rendered via a shared Blade); a concrete subclass just
 * supplies the subject, heading, body lines and an optional CTA. Delivery is best-effort — the
 * BillingNotifier sends these OUTSIDE any billing transaction and swallows failures, so a mail
 * outage never rolls back a payment or an activation.
 */
abstract class BillingMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, string>  $lines
     */
    public function __construct(
        public string $subjectLine,
        public string $heading,
        public array $lines = [],
        public ?string $ctaUrl = null,
        public ?string $ctaLabel = null,
        public ?string $brand = null,
    ) {
        $this->brand ??= config('saas.brand_name', 'Bingoo');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.billing.notification', with: [
            'heading' => $this->heading,
            'lines' => $this->lines,
            'ctaUrl' => $this->ctaUrl,
            'ctaLabel' => $this->ctaLabel,
            'brand' => $this->brand,
        ]);
    }
}
