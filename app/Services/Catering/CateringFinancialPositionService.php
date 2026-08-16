<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringEvent;

/**
 * KASHIF-CATERING-CUSTOMER-CREDIT-1 — where a booking stands, in one place.
 *
 * Three screens used to work this out for themselves, each slightly differently,
 * and each of them clamped the answer at zero:
 *
 *     max($estimate->grand_total - $advanceTotal, 0)
 *
 * So a booking that had taken 492,500 against a 458,250 quotation displayed a
 * balance of 0.00 and looked settled. The 34,250 the business was holding on the
 * customer's behalf appeared nowhere, and no action could reach it.
 *
 * This is now the single calculation, and it does not clamp:
 *
 *     received - refunded = net held
 *     net held - billed   = the position
 *
 *   positive  the customer has paid more than they have been billed
 *             -> CREDIT OWED TO CUSTOMER, settled by a refund
 *   negative  the customer has been billed more than they have paid
 *             -> BALANCE DUE, settled by a receipt
 *
 * Both are reported as their own positive number under their own name, because
 * showing a credit as a negative "balance due" states the exact opposite of the
 * truth about who owes whom.
 *
 * WHAT COUNTS AS BILLED, in order:
 *
 *   final invoice   its grand total — the issued, frozen document wins
 *   cancelled       nothing. A cancelled booking will never be billed, so every
 *                   rupee still held is credit. To keep a cancellation fee,
 *                   revise the quotation down to the fee and then cancel: the
 *                   fee stays billed and only the remainder becomes credit.
 *   otherwise       the current estimate's grand total
 *
 * Read-only by construction. It reads advances, refunds, the invoice and the
 * estimate, and returns numbers. Whoever calls it decides what to do about them.
 */
class CateringFinancialPositionService
{
    public const SOURCE_INVOICE = 'invoice';

    public const SOURCE_ESTIMATE = 'estimate';

    public const SOURCE_CANCELLED = 'cancelled';

    public const SOURCE_NONE = 'none';

    /**
     * @return array{
     *   gross_received: float, refunded: float, net_received: float,
     *   billed: float, billed_source: string, has_invoice: bool,
     *   applied: float, balance_due: float, customer_credit: float,
     *   refundable: float
     * }
     */
    public function position(CateringEvent $event): array
    {
        $grossReceived = round((float) $event->advances()->sum('amount'), 2);
        $refunded = round((float) $event->refunds()->sum('amount'), 2);
        $netReceived = round($grossReceived - $refunded, 2);

        [$billed, $source] = $this->billed($event);

        // The two halves of one number, each reported positively under its own
        // name. Exactly one of them can be non-zero.
        $balanceDue = round(max($billed - $netReceived, 0), 2);
        $customerCredit = round(max($netReceived - $billed, 0), 2);

        return [
            'gross_received' => $grossReceived,
            'refunded' => $refunded,
            'net_received' => $netReceived,
            'billed' => $billed,
            'billed_source' => $source,
            'has_invoice' => $source === self::SOURCE_INVOICE,

            // What the money held has actually gone towards. Never more than the
            // bill, never more than the money.
            'applied' => round(min($netReceived, $billed), 2),

            'balance_due' => $balanceDue,
            'customer_credit' => $customerCredit,

            // Only money that is not covering a bill may be handed back. Refunding
            // out of an unpaid booking would simply recreate the balance due.
            'refundable' => $customerCredit,
        ];
    }

    /**
     * The booking statement — every financial event, in order, with a running
     * position that ends exactly where position() says the booking stands.
     *
     * Every row comes from a persisted record. Nothing here is arithmetic
     * invented for the screen: if a row is on this statement, a document exists
     * behind it, and if a document exists, it is on this statement.
     *
     * The running position is money held less money billed, so it reads the same
     * way the headline does — positive is credit owed to the customer, negative
     * is a balance due. "Advance applied" carries no money because nothing moves:
     * it records that money already held stopped being a deposit and started
     * being payment for a bill.
     *
     * @return array<int, array{
     *   date: string, type: string, reference: ?string, note: ?string,
     *   money_in: float, money_out: float, charged: float,
     *   running: float, informational: bool
     * }>
     */
    public function ledger(CateringEvent $event): array
    {
        $rows = [];

        foreach ($event->advances()->with('paymentMethod')->get() as $advance) {
            $rows[] = [
                'sort_date' => $advance->received_date?->toDateString() ?? '',
                'sort_at' => (string) $advance->created_at,
                'date' => $advance->received_date?->format('d M Y') ?? '—',
                'type' => $advance->posting_type === 'settlement' ? 'Payment received' : 'Advance received',
                'reference' => $advance->reference,
                'note' => $advance->paymentMethod?->name,
                'money_in' => round((float) $advance->amount, 2),
                'money_out' => 0.0,
                'charged' => 0.0,
                'informational' => false,
            ];
        }

        foreach ($event->refunds()->with('paymentMethod')->get() as $refund) {
            $rows[] = [
                'sort_date' => $refund->refund_date?->toDateString() ?? '',
                'sort_at' => (string) $refund->created_at,
                'date' => $refund->refund_date?->format('d M Y') ?? '—',
                'type' => 'Refund paid',
                'reference' => $refund->refund_no,
                'note' => $refund->reason,
                'money_in' => 0.0,
                'money_out' => round((float) $refund->amount, 2),
                'charged' => 0.0,
                'informational' => false,
            ];
        }

        [$billed, $source] = $this->billed($event);

        if ($source === self::SOURCE_INVOICE && $invoice = $event->finalInvoice()->first()) {
            $rows[] = [
                'sort_date' => $invoice->issued_at?->toDateString() ?? '',
                'sort_at' => (string) $invoice->created_at,
                'date' => $invoice->issued_at?->format('d M Y') ?? '—',
                'type' => 'Final invoice issued',
                'reference' => $invoice->invoice_no,
                'note' => null,
                'money_in' => 0.0,
                'money_out' => 0.0,
                'charged' => round((float) $invoice->grand_total, 2),
                'informational' => false,
            ];

            $applied = round((float) ($invoice->advance_applied ?? $invoice->advance_total), 2);
            if ($applied > 0) {
                $rows[] = [
                    'sort_date' => $invoice->issued_at?->toDateString() ?? '',
                    'sort_at' => (string) $invoice->created_at.'~',
                    'date' => $invoice->issued_at?->format('d M Y') ?? '—',
                    'type' => 'Advance applied to invoice',
                    'reference' => $invoice->invoice_no,
                    'note' => 'Money already held, now covering the bill',
                    'money_in' => 0.0,
                    'money_out' => 0.0,
                    'charged' => 0.0,
                    'informational' => true,
                ];
            }
        } elseif ($source === self::SOURCE_ESTIMATE && $estimate = $event->currentEstimate) {
            $rows[] = [
                'sort_date' => $estimate->updated_at?->toDateString() ?? '',
                'sort_at' => (string) $estimate->updated_at,
                'date' => $estimate->updated_at?->format('d M Y') ?? '—',
                'type' => 'Quotation Q'.$estimate->version_no,
                'reference' => null,
                'note' => 'Not yet invoiced',
                'money_in' => 0.0,
                'money_out' => 0.0,
                'charged' => round((float) $estimate->grand_total, 2),
                'informational' => false,
            ];
        } elseif ($source === self::SOURCE_CANCELLED) {
            $rows[] = [
                'sort_date' => $event->cancelled_at?->toDateString() ?? $event->updated_at?->toDateString() ?? '',
                'sort_at' => (string) ($event->cancelled_at ?? $event->updated_at),
                'date' => ($event->cancelled_at ?? $event->updated_at)?->format('d M Y') ?? '—',
                'type' => 'Booking cancelled',
                'reference' => null,
                'note' => 'Nothing will be billed, so everything held is the customer\'s',
                'money_in' => 0.0,
                'money_out' => 0.0,
                'charged' => 0.0,
                'informational' => false,
            ];
        }

        usort($rows, fn ($a, $b) => [$a['sort_date'], $a['sort_at']] <=> [$b['sort_date'], $b['sort_at']]);

        $running = 0.0;
        foreach ($rows as $i => $row) {
            $running = round($running + $row['money_in'] - $row['money_out'] - $row['charged'], 2);
            $rows[$i]['running'] = $running;
            unset($rows[$i]['sort_date'], $rows[$i]['sort_at']);
        }

        return $rows;
    }

    /** @return array{0: float, 1: string} */
    private function billed(CateringEvent $event): array
    {
        if ($invoice = $event->finalInvoice()->first()) {
            return [round((float) $invoice->grand_total, 2), self::SOURCE_INVOICE];
        }

        if ($event->isCancelled()) {
            return [0.0, self::SOURCE_CANCELLED];
        }

        if ($estimate = $event->currentEstimate) {
            return [round((float) $estimate->grand_total, 2), self::SOURCE_ESTIMATE];
        }

        return [0.0, self::SOURCE_NONE];
    }

    /**
     * How the position should be named on screen.
     *
     * Kept here rather than in a Blade file so that every screen says the same
     * thing about the same number.
     *
     * @return array{label: string, amount: float, tone: string, settled: bool}
     */
    public function headline(CateringEvent $event): array
    {
        $position = $this->position($event);

        if ($position['customer_credit'] > 0) {
            return [
                'label' => 'Credit owed to customer',
                'amount' => $position['customer_credit'],
                'tone' => 'warning',
                'settled' => false,
            ];
        }

        if ($position['balance_due'] > 0) {
            return [
                'label' => 'Balance due',
                'amount' => $position['balance_due'],
                'tone' => 'danger',
                'settled' => false,
            ];
        }

        return ['label' => 'Balance due', 'amount' => 0.0, 'tone' => 'success', 'settled' => true];
    }
}
