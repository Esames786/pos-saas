<?php

namespace App\Services\Reports\Delivery;

use App\Mail\SalesReportMail;
use App\Services\Reports\SalesReportDocumentService;
use App\Services\Reports\SalesReportExporter;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * WHATSAPP-REPORT-CHANNEL-1 — email, exactly as it has always worked.
 *
 * Every line here was lifted from the three callers unchanged. That is the point of this step: the
 * code moves, the behaviour does not. If a tenant never turns another channel on, what lands in the
 * owner's inbox after this change is byte-identical to what landed before it.
 */
final class EmailChannel implements ReportChannel
{
    public function __construct(
        private readonly SalesReportDocumentService $document,
        private readonly SalesReportExporter $exporter,
    ) {}

    public function key(): string
    {
        return 'email';
    }

    public function send(ReportDelivery $delivery, array $recipients): void
    {
        $recipients = $this->valid($recipients);

        if ($recipients === []) {
            throw new RuntimeException('No valid report recipient email is configured.');
        }

        Mail::to($recipients)->send($this->mail($delivery));
    }

    private function mail(ReportDelivery $d): SalesReportMail
    {
        if ($d->format === 'a4_pdf') {
            return new SalesReportMail(
                $d->businessName,
                $d->label,
                [],
                $this->document->pdf($d->filters, $d->sections),
                $d->fileName ?: 'sales-report.pdf',
                $d->sections,
            );
        }

        return new SalesReportMail(
            $d->businessName,
            $d->label,
            $this->exporter->sections($d->filters, $d->sections),
        );
    }

    /**
     * @param  list<string> $recipients
     * @return list<string>
     */
    private function valid(array $recipients): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($e) => strtolower(trim((string) $e)), $recipients),
            fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false,
        )));
    }
}
