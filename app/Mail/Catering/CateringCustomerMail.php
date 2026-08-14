<?php

namespace App\Mail\Catering;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * CATERING-SLICE-3: one mailable for all catering customer emails; the
 * $emailType selects subject + intro copy while the estimate/event summary
 * body is shared. Branded with the tenant business name (no hardcoded
 * recipients — the caller resolves the address; spec §11).
 */
class CateringCustomerMail extends Mailable
{
    use Queueable, SerializesModels;

    public const TYPE_BOOKING_CONFIRMED = 'booking_confirmed';

    public const TYPE_QUOTATION_SENT = 'quotation_sent';

    public const TYPE_QUOTATION_REVISED = 'quotation_revised';

    public const TYPE_ADVANCE_RECEIVED = 'advance_received';

    public const TYPE_EVENT_REMINDER = 'event_reminder';

    public const TYPE_FINAL_INVOICE = 'final_invoice';

    public function __construct(
        public string $emailType,
        public string $businessName,
        public CateringEvent $event,
        public ?CateringEstimate $estimate = null,
        public array $context = [],
    ) {}

    public function envelope(): Envelope
    {
        $subject = match ($this->emailType) {
            self::TYPE_BOOKING_CONFIRMED => "Booking Confirmed — {$this->event->event_no}",
            self::TYPE_QUOTATION_SENT => "Your Catering Quotation — {$this->event->event_no}",
            self::TYPE_QUOTATION_REVISED => "Revised Quotation — {$this->event->event_no}",
            self::TYPE_ADVANCE_RECEIVED => "Advance Received — {$this->event->event_no}",
            self::TYPE_EVENT_REMINDER => "Upcoming Event Reminder — {$this->event->event_no}",
            self::TYPE_FINAL_INVOICE => 'Final Invoice '.($this->context['invoice_no'] ?? '')." — {$this->event->event_no}",
            default => "Catering Update — {$this->event->event_no}",
        };

        return new Envelope(subject: $this->businessName.' — '.$subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.catering.customer', with: [
            'emailType' => $this->emailType,
            'businessName' => $this->businessName,
            'event' => $this->event,
            'estimate' => $this->estimate,
            'context' => $this->context,
        ]);
    }
}
