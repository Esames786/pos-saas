<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringFinalInvoice;
use App\Models\Tenant\Printer;
use App\Models\Tenant\PrintJob;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * KASHIF-CATERING-PRODUCT-UX-1 (item 7) — customer documents over the network.
 *
 * Until now only the kitchen sheet could reach a printer without a browser.
 * Estimates and final invoices were A4/browser only, so a counter printer could
 * not produce a quotation slip.
 *
 * Three rules this class exists to keep:
 *
 *  1. NO SECOND QUEUE. Jobs go through the same print_jobs table the LAN agent
 *     already polls, with the same idempotency contract, so nothing new has to
 *     be operated or monitored.
 *
 *  2. PRINTING IS NOT AN ACCOUNTING EVENT. Queueing or reprinting writes one
 *     row in print_jobs and touches nothing else — no journal, no stock, no
 *     invoice. A reprint can never produce a second final invoice, because this
 *     class never creates one; it only reads a document that already exists.
 *
 *  3. URDU IS REFUSED, NOT FAKED. The ESC/POS path emits plain bytes with no
 *     codepage selection and no raster image support, so Urdu cannot be
 *     represented on a thermal printer. Rather than emit mojibake that looks
 *     like a working feature, thermal refuses any Urdu or bilingual request and
 *     the caller is told to use A4.
 */
class CateringDocumentPrintService
{
    private const BOLD_ON = "\x1B\x45\x01";

    private const BOLD_OFF = "\x1B\x45\x00";

    private const SIZE_DOUBLE = "\x1D\x21\x11";

    private const SIZE_NORMAL = "\x1D\x21\x00";

    private const CUT = "\x1D\x56\x42\x00";

    private const FALLBACK_COLS = 42;

    /** Languages a thermal printer can actually render through this transport. */
    public const THERMAL_LANGUAGES = ['en'];

    /**
     * Whether a thermal ticket can honestly represent this language.
     *
     * Kept public so the UI disables the option rather than offering it and
     * failing afterwards — a control that cannot work should not look available.
     */
    public function supportsThermal(?string $lang): bool
    {
        return in_array($lang ?? 'en', self::THERMAL_LANGUAGES, true);
    }

    public function queueEstimate(
        CateringEstimate $estimate,
        Printer $printer,
        string $lang = 'en',
        ?int $userId = null,
        bool $isReprint = false,
    ): PrintJob {
        $this->guardLanguage($lang);

        $estimate->loadMissing(['lines', 'event']);
        $event = $estimate->event;

        $copyNo = $isReprint ? $this->nextCopyNo('catering_estimate', $estimate->id, $printer->id) : 1;

        $payload = [
            'document' => 'estimate',
            'number' => $event?->event_no.' / Q'.$estimate->version_no,
            'status' => $estimate->status,
            'copy_no' => $copyNo,
            'is_reprint' => $isReprint,
            'customer' => $event?->customer_name,
            'phone' => $event?->customer_phone,
            'event_date' => $event?->event_date?->format('d M Y'),
            'service_time' => $event?->service_time,
            'venue' => $event?->venue,
            'pax' => (int) ($event?->pax ?? 0),
            // Commercial values only. Estimated material cost and margin are
            // internal and must never reach a customer-facing document.
            'lines' => $estimate->lines->map(fn ($l) => [
                'item_name' => $l->item_name,
                'quantity' => (float) $l->quantity,
                'unit_code' => $l->unit_code,
                'rate' => (float) $l->rate,
                'amount' => (float) $l->amount,
            ])->values()->all(),
            'service_charge' => (float) $estimate->service_charge_amount,
            'discount' => (float) $estimate->discount_amount,
            'tax' => (float) $estimate->tax_amount,
            'grand_total' => (float) $estimate->grand_total,
        ];

        return $this->createJob(
            documentType: 'catering_estimate',
            referenceType: 'catering_estimate',
            referenceId: $estimate->id,
            referenceNo: $payload['number'],
            branchId: $event?->branch_id,
            printer: $printer,
            payload: $payload,
            logicalKey: $isReprint
                ? "catering-estimate-copy:{$estimate->id}:printer-{$printer->id}:{$copyNo}"
                : "catering-estimate:{$estimate->id}:printer-{$printer->id}",
            copyNo: $copyNo,
            userId: $userId,
        );
    }

    public function queueFinalInvoice(
        CateringFinalInvoice $invoice,
        Printer $printer,
        string $lang = 'en',
        ?int $userId = null,
        bool $isReprint = false,
    ): PrintJob {
        $this->guardLanguage($lang);

        $invoice->loadMissing(['event']);
        $event = $invoice->event;

        $copyNo = $isReprint ? $this->nextCopyNo('catering_final_invoice', $invoice->id, $printer->id) : 1;

        $payload = [
            'document' => 'final_invoice',
            'number' => $invoice->invoice_no,
            'copy_no' => $copyNo,
            'is_reprint' => $isReprint,
            'customer' => $event?->customer_name,
            'phone' => $event?->customer_phone,
            'event_date' => $event?->event_date?->format('d M Y'),
            'venue' => $event?->venue,
            'pax' => (int) ($event?->pax ?? 0),
            'lines' => $invoice->snapshot['lines'] ?? [],
            'grand_total' => (float) $invoice->grand_total,
            'advance_total' => (float) ($invoice->advance_total ?? 0),
            'balance' => (float) $invoice->balance_due,
        ];

        return $this->createJob(
            documentType: 'catering_final_invoice',
            referenceType: 'catering_final_invoice',
            referenceId: $invoice->id,
            referenceNo: $invoice->invoice_no,
            branchId: $event?->branch_id,
            printer: $printer,
            payload: $payload,
            logicalKey: $isReprint
                ? "catering-final-invoice-copy:{$invoice->id}:printer-{$printer->id}:{$copyNo}"
                : "catering-final-invoice:{$invoice->id}:printer-{$printer->id}",
            copyNo: $copyNo,
            userId: $userId,
        );
    }

    private function guardLanguage(string $lang): void
    {
        if (! $this->supportsThermal($lang)) {
            throw new RuntimeException(
                'Thermal printing is available in English only. This transport sends plain ESC/POS '
                .'bytes with no codepage or raster support, so Urdu cannot be rendered on a thermal '
                .'printer. Use the A4 document for Urdu or bilingual output.'
            );
        }
    }

    private function nextCopyNo(string $documentType, int $referenceId, int $printerId): int
    {
        return (int) PrintJob::query()
            ->where('document_type', $documentType)
            ->where('reference_id', $referenceId)
            ->where('printer_id', $printerId)
            ->max('copy_no') + 1;
    }

    private function createJob(
        string $documentType,
        string $referenceType,
        int $referenceId,
        ?string $referenceNo,
        ?int $branchId,
        Printer $printer,
        array $payload,
        string $logicalKey,
        int $copyNo,
        ?int $userId,
    ): PrintJob {
        $attributes = [
            'job_no' => 'PJ-'.now()->format('YmdHis').'-'.random_int(100, 999),
            'logical_key' => $logicalKey,
            'copy_no' => $copyNo,
            'branch_id' => $branchId,
            'printer_id' => $printer->id,
            'document_type' => $documentType,
            'print_status' => 'queued',
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reference_no' => $referenceNo,
            'payload' => $payload,
            'raw_payload' => $this->escPos($payload, $printer),
            'created_by_user_id' => $userId,
        ];

        try {
            return PrintJob::create($attributes);
        } catch (QueryException $exception) {
            // The unique logical_key is the idempotency guarantee: a repeated
            // request for the same document on the same printer returns the job
            // that already exists rather than queueing a second sheet.
            $existing = PrintJob::where('logical_key', $logicalKey)->first();
            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }

    /** English-only ESC/POS ticket, frozen at queue time. */
    private function escPos(array $payload, Printer $printer): string
    {
        $cols = (int) ($printer->characters_per_line ?: self::FALLBACK_COLS);
        $cols = $cols > 0 ? $cols : self::FALLBACK_COLS;

        $center = fn (string $t) => str_pad(mb_substr($t, 0, $cols), $cols, ' ', STR_PAD_BOTH);
        $row = function (string $left, string $right) use ($cols) {
            $space = max(1, $cols - mb_strlen($left) - mb_strlen($right));

            return mb_substr($left, 0, $cols).str_repeat(' ', $space).$right;
        };
        $rule = str_repeat('-', $cols);

        $title = $payload['document'] === 'estimate' ? 'QUOTATION' : 'FINAL INVOICE';

        $out = self::SIZE_DOUBLE.self::BOLD_ON.$center($title)."\n".self::SIZE_NORMAL.self::BOLD_OFF;

        if (! empty($payload['is_reprint'])) {
            $out .= self::BOLD_ON.$center('*** COPY #'.$payload['copy_no'].' ***')."\n".self::BOLD_OFF;
        }

        $out .= $center($payload['number'] ?? '')."\n".$rule."\n";
        $out .= self::BOLD_ON.($payload['customer'] ?? '')."\n".self::BOLD_OFF;

        if (! empty($payload['phone'])) {
            $out .= $payload['phone']."\n";
        }
        if (! empty($payload['event_date'])) {
            $out .= $row('Date', $payload['event_date'])."\n";
        }
        if (! empty($payload['venue'])) {
            $out .= $row('Venue', mb_substr($payload['venue'], 0, (int) ($cols / 2)))."\n";
        }
        $out .= $row('Guests', number_format((int) ($payload['pax'] ?? 0)))."\n".$rule."\n";

        foreach ($payload['lines'] as $line) {
            $name = $line['item_name'] ?? ($line['name'] ?? '');
            $qty = rtrim(rtrim(number_format((float) ($line['quantity'] ?? 0), 2), '0'), '.');
            $unit = $line['unit_code'] ?? '';
            $amount = number_format((float) ($line['amount'] ?? 0), 2);

            $out .= mb_substr($name, 0, $cols)."\n";
            $out .= $row('  '.$qty.' '.$unit.' x '.number_format((float) ($line['rate'] ?? 0), 2), $amount)."\n";
        }

        $out .= $rule."\n";

        foreach ([
            'Service charge' => $payload['service_charge'] ?? 0,
            'Discount' => $payload['discount'] ?? 0,
            'Tax' => $payload['tax'] ?? 0,
        ] as $label => $value) {
            if ((float) $value != 0.0) {
                $out .= $row($label, number_format((float) $value, 2))."\n";
            }
        }

        $out .= self::BOLD_ON.$row('TOTAL', number_format((float) ($payload['grand_total'] ?? 0), 2))."\n".self::BOLD_OFF;

        if (isset($payload['advance_total'])) {
            $out .= $row('Advance received', number_format((float) $payload['advance_total'], 2))."\n";
            $out .= self::BOLD_ON.$row('BALANCE DUE', number_format((float) $payload['balance'], 2))."\n".self::BOLD_OFF;
        }

        return $out."\n\n".self::CUT;
    }
}
