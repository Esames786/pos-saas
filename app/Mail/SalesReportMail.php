<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** SALES REPORT CENTER (spec Z/AA) — report email with CSV section attachments. */
class SalesReportMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<string,string> $csvSections section => csv content */
    public function __construct(
        public string $businessName,
        public string $periodLabel,
        public array $csvSections,
        public ?string $pdfContent = null,
        public ?string $pdfFilename = null,
        public array $reportSections = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->businessName.' — Sales Report ('.$this->periodLabel.')');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.sales-report');
    }

    public function attachments(): array
    {
        $attachments = [];
        foreach ($this->csvSections as $section => $csv) {
            $attachments[] = Attachment::fromData(fn () => $csv, $section.'.csv')->withMime('text/csv');
        }
        if ($this->pdfContent !== null) {
            $attachments[] = Attachment::fromData(
                fn () => $this->pdfContent,
                $this->pdfFilename ?: 'sales-report.pdf'
            )->withMime('application/pdf');
        }

        return $attachments;
    }
}
