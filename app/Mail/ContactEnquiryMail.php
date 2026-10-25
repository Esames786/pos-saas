<?php

namespace App\Mail;

use App\Models\Master\ContactEnquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * CONTACT-FORM-1 — the enquiry itself, to the sales inbox.
 *
 * `replyTo` par wo bhejne wala lagta hai, is liye sales team seedha "Reply" daba kar jawab de sakti
 * hai. `from` nahi badla jata: mail server sirf apne hi domain se bhejta hai, aur kisi ajnabi ke
 * pate se bhejna SPF/DMARC ki wajah se mail ko spam me daal deta hai.
 *
 * Plain strings nahi, poora model: ye queue par jata hai aur Laravel model ko dobara DB se parh
 * leta hai — enquiry master me mehfooz hai, is liye ye mehfooz hai.
 */
class ContactEnquiryMail extends Mailable
{
    use Queueable;

    public function __construct(public ContactEnquiry $enquiry) {}

    public function envelope(): Envelope
    {
        $who = $this->enquiry->business_name ?: $this->enquiry->name;

        return new Envelope(
            subject: __('New enquiry from :who', ['who' => $who]),
            replyTo: [new Address($this->enquiry->email, $this->enquiry->name)],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.contact-enquiry');
    }
}
