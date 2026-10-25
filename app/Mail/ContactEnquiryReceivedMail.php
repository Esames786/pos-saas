<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * CONTACT-FORM-1 — "we got it" to the person who wrote in.
 *
 * Plain strings only: ye queue par jata hai aur is me koi aisi cheez nahi jo baad me badal sake.
 * `replyTo` sales ka hai — banda is email ka jawab de to wo sales ke paas jaye, kisi khaali
 * no-reply inbox me nahi.
 */
class ContactEnquiryReceivedMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $brand,
        public string $name,
        public string $salesEmail,
        public ?string $phone = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('We received your message — :brand', ['brand' => $this->brand]),
            replyTo: [new Address($this->salesEmail)],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.contact-enquiry-received');
    }
}
