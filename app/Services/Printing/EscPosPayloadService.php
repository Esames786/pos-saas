<?php

namespace App\Services\Printing;

use App\Models\Tenant\Branch;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Support\TenantClock;

class EscPosPayloadService
{
    /**
     * SHIFT-TIMEZONE-BUSINESS-DATE-1 (N): a printed ticket shows the store's local wall-clock time.
     * DB timestamps are UTC-canonical, so we render them in the branch business timezone. This is
     * a pure display concern — the ESC-POS engine and stored data are unchanged.
     */
    /**
     * SHIFT-TIMEZONE-BUSINESS-DATE-HARDEN-1: a printed/reprinted ticket must show the local time of
     * the ORIGINAL operational context, not the branch's current timezone. So we prefer the frozen
     * shift timezone, then a timezone snapshot carried in the print payload, then the branch's
     * current timezone, then the platform default. Immutable-first so changing a branch timezone
     * later never shifts the time on a historical receipt/reprint. DB-free (no currentBranch()).
     */
    private function printTz(?SalesOrder $sale, ?string $payloadTz = null): string
    {
        $clock = app(TenantClock::class);

        return $clock->normalize($sale?->shift?->timezone_name)
            ?? $clock->normalize($payloadTz)
            ?? $clock->normalize($sale?->branch?->timezone)
            ?? TenantClock::DEFAULT_TIMEZONE;
    }
    public function build(PrintJob $job): string
    {
        if ($job->reference_type !== 'sales_order') {
            return '';
        }

        if ($job->document_type === 'reminder') {
            return $this->buildReminder($job);
        }

        $sale = SalesOrder::with([
            'branch',
            'shift',
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
            'kot'                => $this->kot($sale, $job),
            'receipt', 'invoice' => $this->receipt($sale),
            default              => $this->receipt($sale),
        };
    }

    /** Render exclusively from the immutable job payload. */
    public function buildReminder(PrintJob $job): string
    {
        $payload = $job->payload ?? [];
        // Render reminder timestamps in the ORIGINAL operational timezone (frozen shift tz, then a
        // payload snapshot, then branch, then default). The lookup is guarded so pure-payload
        // rendering (no tenant DB bound) still works, defaulting the timezone.
        $sale = null;
        try {
            $sale = SalesOrder::with(['branch', 'shift'])->find($job->reference_id);
        } catch (\Throwable) {
            // no tenant DB context — fall back to the payload snapshot / default timezone
        }
        $tz = $this->printTz($sale, $payload['timezone'] ?? null);
        $revision = max((int) ($payload['revision'] ?? 1), 1);
        $copyNo = max((int) ($payload['copy_no'] ?? 1), 1);
        $eventType = (string) ($payload['event_type'] ?? 'order');
        $layout = $payload['layout'] ?? [];
        $header = trim((string) ($layout['header_text'] ?? ''));
        if ($header !== '') {
            $out = $this->center($header) . "\n";
        } else {
            $out = '';
        }
        $out .= $this->center('*** REMINDER ***') . "\n";
        $out .= $this->center((string) ($payload['heading'] ?? 'REMINDER')) . "\n";
        if (!in_array($eventType, ['cancelled_order', 'cancelled_updated_order'], true)) {
            $out .= $this->center('REVISION ' . $revision) . "\n";
        }
        if (!empty($payload['is_reprint'])) {
            $out .= $this->center('DUPLICATE ' . $copyNo) . "\n";
        }
        if ($layout['show_order_no'] ?? true) {
            $out .= $this->center((string) ($payload['sale_no'] ?? '')) . "\n";
        }
        $out .= str_repeat('-', 42) . "\n";
        if (($layout['show_table_info'] ?? true) && !empty($payload['table'])) {
            $out .= 'TABLE: ' . $payload['table'] . "\n";
        }
        if (($layout['show_table_info'] ?? true) && !empty($payload['waiter'])) {
            $out .= 'WAITER: ' . $payload['waiter'] . "\n";
        }
        if (($layout['show_cashier_name'] ?? true) && !empty($payload['cashier'])) {
            $out .= 'CASHIER: ' . $payload['cashier'] . "\n";
        }
        if (($layout['show_customer_name'] ?? false) && !empty($payload['customer'])) {
            $out .= 'CUSTOMER: ' . $payload['customer'] . "\n";
        }
        $out .= 'TYPE: ' . strtoupper(str_replace('_', ' ', (string) ($payload['order_type'] ?? 'SALE'))) . "\n";
        if (($layout['show_order_time'] ?? true) && !empty($payload['order_time'])) {
            $out .= 'ORDER: ' . $this->formatTimestamp($payload['order_time'], $tz) . "\n";
        }
        if (($layout['show_updated_time'] ?? true) && !empty($payload['updated_time'])) {
            $out .= 'UPDATED: ' . $this->formatTimestamp($payload['updated_time'], $tz) . "\n";
        }
        if (($layout['show_print_time'] ?? true) && !empty($payload['generated_at'])) {
            $out .= 'PRINT: ' . $this->formatTimestamp($payload['generated_at'], $tz) . "\n";
        }
        $out .= str_repeat('-', 42) . "\n";

        if (!empty($payload['cancelled_lines'])) {
            $out .= "CANCELLED:\n";
            foreach ($payload['cancelled_lines'] as $line) {
                $out .= $this->reminderLine($line, false) . "\n";
            }
            $out .= str_repeat('-', 42) . "\n";
            $out .= "REMAINING ORDER:\n";
        }

        $lines = collect($payload['lines'] ?? []);
        $topLevel = $lines->filter(fn ($line) => empty($line['parent_line_id']));
        if ($topLevel->isEmpty()) {
            $out .= "NO REMAINING ITEMS\n";
        }
        foreach ($topLevel as $line) {
            $out .= $this->reminderLine($line, $revision > 1) . "\n";
            foreach ($lines->where('parent_line_id', $line['line_id'] ?? null) as $component) {
                $out .= '  - ' . $this->reminderLine($component, $revision > 1) . "\n";
                foreach (($component['modifiers'] ?? []) as $modifier) {
                    if (!empty($modifier['name'])) { $out .= '    + ' . $modifier['name'] . "\n"; }
                }
                if (!empty($component['kitchen_note'])) { $out .= '    NOTE: ' . $component['kitchen_note'] . "\n"; }
            }
            foreach (($line['modifiers'] ?? []) as $modifier) {
                if (!empty($modifier['name'])) {
                    $out .= '  + ' . $modifier['name'] . "\n";
                }
            }
            if (!empty($line['kitchen_note'])) {
                $out .= '  NOTE: ' . $line['kitchen_note'] . "\n";
            }
        }

        $audit = collect($payload['cancellation_audit'] ?? [])->first();
        if ($audit) {
            $out .= str_repeat('-', 42) . "\n";
            if (!empty($audit['reason'])) { $out .= 'REASON: ' . $audit['reason'] . "\n"; }
            if (!empty($audit['requested_by'])) { $out .= 'REQUESTED BY: ' . $audit['requested_by'] . "\n"; }
            if (!empty($audit['approved_by'])) { $out .= 'APPROVED BY: ' . $audit['approved_by'] . "\n"; }
            if (!empty($audit['requested_at'])) { $out .= 'REQUESTED: ' . $this->formatTimestamp($audit['requested_at'], $tz) . "\n"; }
            if (!empty($audit['approved_at'])) { $out .= 'APPROVED: ' . $this->formatTimestamp($audit['approved_at'], $tz) . "\n"; }
        }
        if (!empty($payload['order_note'])) {
            $out .= str_repeat('-', 42) . "\nORDER NOTE:\n" . $payload['order_note'] . "\n";
        }

        $footer = trim((string) ($layout['footer_text'] ?? ''));
        if ($footer !== '') {
            $out .= str_repeat('-', 42) . "\n" . $this->center($footer) . "\n";
        }

        return $out . str_repeat('-', 42) . "\n\n\n";
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
        $tz = $this->printTz($sale);
        $out .= 'Date: ' . ($sale->sale_date ? $sale->sale_date->copy()->timezone($tz)->format('Y-m-d H:i') : '') . "\n";
        $out .= 'Cashier: ' . ($sale->createdBy?->name ?? '-') . "\n";

        if ($sale->restaurantTable) {
            $out .= 'Table: ' . $sale->restaurantTable->table_no . "\n";
        }
        if ($sale->restaurantWaiter) {
            $out .= 'Waiter: ' . $sale->restaurantWaiter->name . "\n";
        }
        if ($sale->deliveryChannel) {
            $out .= 'Channel: ' . $sale->deliveryChannel->name . "\n";
        }
        if ($sale->deliveryRider) {
            $out .= 'Rider: ' . $sale->deliveryRider->name . "\n";
        }
        if ($sale->delivery_address) {
            $out .= 'Deliver to: ' . $sale->delivery_address . "\n";
        }

        $out .= str_repeat('-', 42) . "\n";

        foreach ($sale->lines as $line) {
            if (($line->line_kind ?? 'standard') === 'component') {
                continue;
            }

            $name  = mb_substr($line->product_name ?? '', 0, 24);
            $qty   = number_format((float) $line->quantity, 3);
            if ($line->unit_code) { $qty .= ' ' . $line->unit_code; }
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

            foreach ($this->lineModifiers($line) as $modifier) {
                $label = '  + ' . $modifier['name'];
                $delta = (float) ($modifier['price_delta'] ?? 0);
                if ($delta !== 0.0) {
                    $label .= ' (' . ($delta > 0 ? '+' : '') . number_format($delta, 2) . ')';
                }
                $out .= $label . "\n";
            }

            foreach ($sale->lines->where('parent_sales_order_line_id', $line->id) as $component) {
                $componentQty = number_format((float) $component->quantity, 3);
                if ($component->unit_code) {
                    $componentQty .= ' ' . $component->unit_code;
                }
                $out .= '  - ' . $componentQty . ' x ' . ($component->product_name ?? '') . "\n";
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
        if ((float) ($sale->service_charge_amount ?? 0) > 0) {
            $out .= $this->columns('Service Charge', number_format((float) $sale->service_charge_amount, 2), 42) . "\n";
        }
        if ((float) ($sale->tip_amount ?? 0) > 0) {
            $out .= $this->columns('Tip', number_format((float) $sale->tip_amount, 2), 42) . "\n";
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

    private function kot(SalesOrder $sale, PrintJob $job): string
    {
        $payload        = $job->payload ?? [];
        $isReprint      = !empty($payload['is_reprint']);
        $eventType      = $payload['kot_event_type'] ?? ($isReprint ? 'duplicate' : 'normal');
        $sequenceNo     = (int) ($payload['kot_sequence_no'] ?? 0);
        $copyNo         = (int) ($payload['copy_no'] ?? 1);
        $lineQuantities = collect($payload['line_quantities'] ?? []);

        $lineIds = collect($payload['line_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();

        $lines = $lineIds->isNotEmpty()
            ? $sale->lines->whereIn('id', $lineIds)->values()
            : $sale->lines;

        if ($eventType === 'cancel' && !empty($payload['line_snapshots'])) {
            $lines = collect($payload['line_snapshots'])->map(fn ($line) => (object) $line);
        }

        $out = '';

        $heading = match ($eventType) {
            'cancel' => '*** CANCEL KOT #' . $sequenceNo . ' ***',
            'addition' => '*** ADDITION KOT #' . $sequenceNo . ' ***',
            'duplicate' => '*** DUPLICATE KOT #' . $sequenceNo . ' ***',
            default => '*** KOT #' . ($sequenceNo ?: 1) . ' ***',
        };
        $out .= $this->center($heading) . "\n";
        if ($eventType === 'duplicate') {
            $out .= $this->center('DUPLICATE ' . max($copyNo, 1)) . "\n";
        }
        $out .= $this->center($sale->sale_no ?? '') . "\n";
        $out .= str_repeat('-', 42) . "\n";

        if ($sale->restaurantTable) {
            $out .= 'TABLE: ' . $sale->restaurantTable->table_no . "\n";
        }
        if ($sale->restaurantWaiter) {
            $out .= 'WAITER: ' . $sale->restaurantWaiter->name . "\n";
        }

        $out .= 'TYPE: ' . strtoupper(str_replace('_', ' ', $sale->order_type ?? 'SALE')) . "\n";
        $out .= 'TIME: ' . now()->timezone($this->printTz($sale))->format('Y-m-d H:i') . "\n";
        $out .= str_repeat('-', 42) . "\n";

        foreach ($lines as $line) {
            if (($line->line_kind ?? 'standard') === 'combo_header') {
                continue;
            }

            // Use stored quantity from payload when available; fall back to model quantity.
            $lineId = $line->line_id ?? $line->id ?? null;
            $qtyToPrint = $lineId && $lineQuantities->has((string) $lineId)
                ? (float) $lineQuantities->get((string) $lineId)
                : (float) $line->quantity;

            if ($qtyToPrint <= 0) {
                continue;
            }

            $runningPrefix = $eventType === 'addition' ? '(R) ' : '';
            $out .= $runningPrefix . strtoupper($line->product_name ?? '') . "\n";
            $kotQty = number_format($qtyToPrint, 3);
            if ($line->unit_code) { $kotQty .= ' ' . $line->unit_code; }
            $out .= 'QTY: ' . $kotQty . "\n";

            if ($line->variant_name) {
                $out .= "Variant: {$line->variant_name}\n";
            }
            foreach ($this->lineModifiers($line) as $modifier) {
                $out .= '+ ' . $modifier['name'] . "\n";
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

    private function lineModifiers($line): array
    {
        $modifiers = $line->modifiers ?? [];

        if (is_string($modifiers)) {
            $decoded = json_decode($modifiers, true);
            $modifiers = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($modifiers)) {
            return [];
        }

        return collect($modifiers)
            ->filter(fn ($modifier) => is_array($modifier) && !empty($modifier['name']))
            ->map(fn ($modifier) => [
                'name' => (string) $modifier['name'],
                'price_delta' => (float) ($modifier['price_delta'] ?? 0),
            ])
            ->values()
            ->all();
    }

    private function reminderLine(array $line, bool $showRunning): string
    {
        $quantity = (float) ($line['quantity'] ?? 0);
        $delta = (float) ($line['round_delta'] ?? 0);
        $prefix = $showRunning && $delta > 0 && abs($delta - $quantity) < 0.000001 ? '(R) ' : '';
        $suffix = $showRunning && $delta > 0 && $delta < $quantity
            ? ' (R +' . $this->quantity($delta) . ')'
            : '';
        $unit = !empty($line['unit_code']) ? ' ' . $line['unit_code'] : '';

        return $prefix . strtoupper((string) ($line['product_name'] ?? 'ITEM'))
            . ' x' . $this->quantity($quantity) . $unit . $suffix;
    }

    private function quantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');
    }

    private function formatTimestamp(string $timestamp, ?string $tz = null): string
    {
        try {
            $moment = \Carbon\Carbon::parse($timestamp);
            if ($tz) {
                $moment = $moment->timezone($tz);
            }
            return $moment->format('Y-m-d H:i');
        } catch (\Throwable) {
            return $timestamp;
        }
    }
}
