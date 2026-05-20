<?php

namespace App\Services\Printing;

use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;

class EscPosPayloadService
{
    public function build(PrintJob $job): string
    {
        if ($job->reference_type !== 'sales_order') {
            return '';
        }

        $sale = SalesOrder::with([
            'branch',
            'terminal',
            'customer',
            'createdBy',
            'restaurantTable',
            'restaurantWaiter',
            'lines',
            'payments.method',
        ])->find($job->reference_id);

        if (!$sale) {
            return '';
        }

        return match ($job->document_type) {
            'kot'               => $this->kot($sale),
            'receipt', 'invoice' => $this->receipt($sale),
            default             => $this->receipt($sale),
        };
    }

    private function receipt(SalesOrder $sale): string
    {
        $out = '';

        $out .= $this->center($sale->branch?->name ?? 'Receipt') . "\n";
        if ($sale->branch?->phone) {
            $out .= $this->center($sale->branch->phone) . "\n";
        }
        $out .= str_repeat('-', 42) . "\n";
        $out .= "Receipt: {$sale->sale_no}\n";
        $out .= 'Date: ' . optional($sale->sale_date)->format('Y-m-d H:i') . "\n";
        $out .= 'Cashier: ' . ($sale->createdBy?->name ?? '-') . "\n";

        if ($sale->restaurantTable) {
            $out .= 'Table: ' . $sale->restaurantTable->table_no . "\n";
        }
        if ($sale->restaurantWaiter) {
            $out .= 'Waiter: ' . $sale->restaurantWaiter->name . "\n";
        }

        $out .= str_repeat('-', 42) . "\n";

        foreach ($sale->lines as $line) {
            $name  = mb_substr($line->product_name ?? '', 0, 24);
            $qty   = number_format((float) $line->quantity, 2);
            $total = number_format((float) $line->line_total, 2);

            $out .= $name . "\n";
            $out .= $this->columns(
                "  {$qty} x " . number_format((float) $line->unit_price, 2),
                $total,
                42
            ) . "\n";

            if ($line->kitchen_note) {
                $out .= "  * {$line->kitchen_note}\n";
            }
        }

        $out .= str_repeat('-', 42) . "\n";
        $out .= $this->columns('Subtotal', number_format((float) $sale->subtotal, 2), 42) . "\n";

        if ((float) $sale->discount_amount > 0) {
            $out .= $this->columns('Discount', '-' . number_format((float) $sale->discount_amount, 2), 42) . "\n";
        }
        if ((float) $sale->tax_amount > 0) {
            $out .= $this->columns('Tax', number_format((float) $sale->tax_amount, 2), 42) . "\n";
        }

        $out .= $this->columns('TOTAL', number_format((float) $sale->grand_total, 2), 42) . "\n";
        $out .= $this->columns('Paid', number_format((float) $sale->paid_amount, 2), 42) . "\n";
        $out .= $this->columns('Change', number_format((float) $sale->change_amount, 2), 42) . "\n";

        $out .= str_repeat('-', 42) . "\n";

        foreach ($sale->payments as $payment) {
            $methodName = $payment->method?->name ?? ucfirst($payment->payment_method ?? 'Payment');
            $out .= $this->columns($methodName, number_format((float) $payment->amount, 2), 42) . "\n";
        }

        $out .= str_repeat('-', 42) . "\n";
        $out .= $this->center('Thank you for your visit!') . "\n\n\n";

        return $out;
    }

    private function kot(SalesOrder $sale): string
    {
        $out = '';

        $out .= $this->center('*** KOT ***') . "\n";
        $out .= $this->center($sale->sale_no ?? '') . "\n";
        $out .= str_repeat('-', 42) . "\n";

        if ($sale->restaurantTable) {
            $out .= 'TABLE: ' . $sale->restaurantTable->table_no . "\n";
        }
        if ($sale->restaurantWaiter) {
            $out .= 'WAITER: ' . $sale->restaurantWaiter->name . "\n";
        }

        $out .= 'TYPE: ' . strtoupper(str_replace('_', ' ', $sale->order_type ?? 'SALE')) . "\n";
        $out .= 'TIME: ' . now()->format('Y-m-d H:i') . "\n";
        $out .= str_repeat('-', 42) . "\n";

        foreach ($sale->lines as $line) {
            $out .= strtoupper($line->product_name ?? '') . "\n";
            $out .= 'QTY: ' . number_format((float) $line->quantity, 2) . "\n";

            if ($line->variant_name) {
                $out .= "Variant: {$line->variant_name}\n";
            }
            if ($line->kitchen_note) {
                $out .= "NOTE: {$line->kitchen_note}\n";
            }

            $out .= "\n";
        }

        if ($sale->notes) {
            $out .= str_repeat('-', 42) . "\n";
            $out .= "ORDER NOTE:\n{$sale->notes}\n";
        }

        $out .= str_repeat('-', 42) . "\n\n\n";

        return $out;
    }

    private function center(string $text, int $width = 42): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $padding = max((int) floor(($width - mb_strlen($text)) / 2), 0);
        return str_repeat(' ', $padding) . $text;
    }

    private function columns(string $left, string $right, int $width = 42): string
    {
        $space = max($width - mb_strlen($left) - mb_strlen($right), 1);
        return $left . str_repeat(' ', $space) . $right;
    }
}
