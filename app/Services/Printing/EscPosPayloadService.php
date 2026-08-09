<?php

namespace App\Services\Printing;

use App\Models\Tenant\Branch;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Support\TenantClock;

class EscPosPayloadService
{
    /**
     * ESC/POS "feed and partial cut" (GS V B 0). Raw port-9100 printing bypasses the Windows
     * driver, so the printer's "auto cut" driver setting never applies — the cut must be a byte
     * command inside the payload (Black Copper / Epson-compatible; harmless on printers
     * without a cutter).
     */
    private const CUT = "\x1D\x56\x42\x00";

    /** Centered "BingooPos / Bingoopos.com" branding footer (per-layout toggle). */
    private function brandingFooter(): string
    {
        return $this->center('BingooPos') . "\n" . $this->center('Bingoopos.com') . "\n";
    }

    /** The branch's saved layout row for this document type (null = never configured). */
    private function layoutFor(?int $branchId, string $documentType): ?\App\Models\Tenant\ReceiptLayoutSetting
    {
        try {
            return \App\Models\Tenant\ReceiptLayoutSetting::where('document_type', $documentType)
                ->where(function ($q) use ($branchId) {
                    $q->whereNull('branch_id');
                    if ($branchId) {
                        $q->orWhere('branch_id', $branchId);
                    }
                })
                ->orderByDesc('branch_id')
                ->first();
        } catch (\Throwable) {
            return null; // payload-only rendering without a tenant DB bound
        }
    }

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
        // PRINT-FORMAT-PARITY-1: order type leads the ticket (kitchen reads it first).
        $out .= $this->center('** ' . strtoupper(str_replace('_', ' ', (string) ($payload['order_type'] ?? 'SALE'))) . ' **') . "\n";
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
        if (!empty($payload['vehicle'])) {
            $out .= 'VEHICLE: ' . $payload['vehicle'] . "\n";
        }
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
        if ($layout['show_bingoo_branding'] ?? false) {
            $out .= $this->brandingFooter();
        }

        return $out . str_repeat('-', 42) . "\n\n\n" . self::CUT;
    }

    private function receipt(SalesOrder $sale): string
    {
        // PRINT-FORMAT-PARITY-1: the physical slip honours the SAME saved layout as the
        // browser preview (missing row keeps every toggle's documented default), the order
        // type leads the ticket, and items are single-line with a qty prefix.
        $layout = $this->layoutFor($sale->branch_id, 'receipt');
        $show = fn (string $field, bool $default = true) => $layout === null ? $default : (bool) $layout->{$field};
        $out = '';

        if ($show('show_branch_name')) {
            $out .= $this->center($sale->branch?->name ?? 'Receipt') . "\n";
        }
        if ($show('show_branch_address') && $sale->branch?->address) {
            $out .= $this->center($sale->branch->address) . "\n";
        }
        if ($show('show_branch_phone') && $sale->branch?->phone) {
            $out .= $this->center('Tel: ' . $sale->branch->phone) . "\n";
        }
        if ($show('show_tax_number') && $sale->branch?->tax_number) {
            $out .= $this->center('Tax No: ' . $sale->branch->tax_number) . "\n";
        }
        $headerText = trim((string) ($layout?->header_text ?? ''));
        if ($headerText !== '') {
            $out .= $this->center($headerText) . "\n";
        }
        $out .= str_repeat('-', 42) . "\n";
        $out .= $this->center('** ' . strtoupper(str_replace('_', ' ', $sale->order_type ?? 'SALE')) . ' **') . "\n";
        $out .= str_repeat('-', 42) . "\n";
        if ($show('show_order_no')) {
            $out .= "Receipt: {$sale->sale_no}\n";
        }
        $tz = $this->printTz($sale);
        $out .= 'Date: ' . ($sale->sale_date ? $sale->sale_date->copy()->timezone($tz)->format('Y-m-d H:i') : '') . "\n";
        if ($show('show_cashier_name')) {
            $out .= 'Cashier: ' . ($sale->createdBy?->name ?? '-') . "\n";
        }
        if ($show('show_customer_name', false) && ($sale->customer?->name ?? $sale->customer_name)) {
            $out .= 'Customer: ' . ($sale->customer?->name ?? $sale->customer_name) . "\n";
        }

        if ($show('show_table_info')) {
            if ($sale->restaurantTable) {
                $out .= 'Table: ' . $sale->restaurantTable->table_no . "\n";
            }
            if ($sale->restaurantWaiter) {
                $out .= 'Waiter: ' . $sale->restaurantWaiter->name . "\n";
            }
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
        if ($sale->vehicle_number) {
            $out .= 'Vehicle: ' . $sale->vehicle_number . "\n";
        }

        $out .= str_repeat('-', 42) . "\n";

        foreach ($sale->lines as $line) {
            if (($line->line_kind ?? 'standard') === 'component') {
                continue;
            }

            // Single line: "2x Beef Changezi Pulao @450.00      900.00" — qty leads,
            // unit price shown inline only when qty ≠ 1, total right-aligned. When space
            // runs out, the NAME is truncated — the price is dropped whole, never mangled
            // ("@450.00" must never print as "@45").
            $qty = $this->quantity((float) $line->quantity);
            $total = number_format((float) $line->line_total, 2);
            $qtyPrefix = $qty . 'x ';
            $priceSuffix = (float) $line->quantity !== 1.0 ? ' @' . number_format((float) $line->unit_price, 2) : '';
            $maxName = 41 - mb_strlen($total) - mb_strlen($qtyPrefix) - mb_strlen($priceSuffix);
            if ($maxName < 8) {
                $priceSuffix = '';
                $maxName = 41 - mb_strlen($total) - mb_strlen($qtyPrefix);
            }
            $left = $qtyPrefix . mb_substr($line->product_name ?? '', 0, max($maxName, 1)) . $priceSuffix;
            $out .= $this->columns($left, $total, 42) . "\n";

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
        if ((float) ($sale->delivery_charge_amount ?? 0) > 0) {
            $out .= $this->columns('Delivery Charge', number_format((float) $sale->delivery_charge_amount, 2), 42) . "\n";
        }
        if ((float) ($sale->tip_amount ?? 0) > 0) {
            $out .= $this->columns('Tip', number_format((float) $sale->tip_amount, 2), 42) . "\n";
        }

        $out .= $this->columns('TOTAL', number_format((float) $sale->grand_total, 2), 42) . "\n";
        $out .= $this->columns('Paid', number_format((float) $sale->paid_amount, 2), 42) . "\n";
        $out .= $this->columns('Change', number_format((float) $sale->change_amount, 2), 42) . "\n";

        $out .= str_repeat('-', 42) . "\n";

        if ($show('show_payment_breakdown')) {
            foreach ($sale->payments as $payment) {
                $methodName = $payment->method?->name ?? ucfirst($payment->payment_method ?? 'Payment');
                // Show the physical cash handed over (tendered) when captured — "Cash 5,000.00 /
                // Change 1,400.00" tells the drawer story; applied-only amounts hide the change.
                $out .= $this->columns($methodName, number_format((float) ($payment->tendered_amount ?? $payment->amount), 2), 42) . "\n";
            }
            $out .= str_repeat('-', 42) . "\n";
        }

        $footerText = trim((string) ($layout?->footer_text ?? ''));
        $out .= $this->center($footerText !== '' ? $footerText : 'Thank you for your visit!') . "\n";
        // Branding default: ON for receipts unless the branch layout explicitly turns it off.
        if ($show('show_bingoo_branding')) {
            $out .= $this->brandingFooter();
        }
        $out .= "\n\n";

        return $out . self::CUT;
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

        // PRINT-FORMAT-PARITY-1: honour the saved KOT layout like the browser preview does,
        // lead with the ORDER TYPE (kitchen reads it first), single-line item + qty column.
        $layout = $this->layoutFor($sale->branch_id, 'kot');
        $show = fn (string $field, bool $default = true) => $layout === null ? $default : (bool) $layout->{$field};
        $out = '';

        $headerText = trim((string) ($layout?->header_text ?? ''));
        if ($headerText !== '') {
            $out .= $this->center($headerText) . "\n";
        }

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
        $out .= $this->center('** ' . strtoupper(str_replace('_', ' ', $sale->order_type ?? 'SALE')) . ' **') . "\n";
        if ($show('show_order_no')) {
            $out .= $this->center($sale->sale_no ?? '') . "\n";
        }
        $out .= str_repeat('-', 42) . "\n";

        if ($show('show_table_info')) {
            if ($sale->restaurantTable) {
                $out .= 'TABLE: ' . $sale->restaurantTable->table_no . "\n";
            }
            if ($sale->restaurantWaiter) {
                $out .= 'WAITER: ' . $sale->restaurantWaiter->name . "\n";
            }
        }
        if ($show('show_cashier_name') && $sale->createdBy) {
            $out .= 'CASHIER: ' . $sale->createdBy->name . "\n";
        }
        if ($sale->vehicle_number) {
            $out .= 'VEHICLE: ' . $sale->vehicle_number . "\n";
        }
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

            // Single line, qty right-aligned: "BEEF CHANGEZI PULAO (1 KG)            2"
            $runningPrefix = $eventType === 'addition' ? '(R) ' : '';
            $kotQty = $this->quantity($qtyToPrint);
            if ($line->unit_code) { $kotQty .= ' ' . $line->unit_code; }
            $name = mb_substr($runningPrefix . strtoupper($line->product_name ?? ''), 0, 41 - mb_strlen($kotQty));
            $out .= $this->columns($name, $kotQty, 42) . "\n";

            if ($line->variant_name) {
                $out .= "  Variant: {$line->variant_name}\n";
            }
            foreach ($this->lineModifiers($line) as $modifier) {
                $out .= '  + ' . $modifier['name'] . "\n";
            }
            if ($line->kitchen_note) {
                $out .= "  NOTE: {$line->kitchen_note}\n";
            }
        }

        if ($sale->notes) {
            $out .= str_repeat('-', 42) . "\n";
            $out .= "ORDER NOTE:\n{$sale->notes}\n";
        }

        $footerText = trim((string) ($layout?->footer_text ?? ''));
        if ($footerText !== '') {
            $out .= str_repeat('-', 42) . "\n" . $this->center($footerText) . "\n";
        }
        // Branding on KOT only when the branch layout explicitly enables it (default OFF).
        if ($show('show_bingoo_branding', false)) {
            $out .= $this->brandingFooter();
        }

        $out .= str_repeat('-', 42) . "\n\n\n";

        return $out . self::CUT;
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
        // PRINT-FORMAT-PARITY-1: single line, qty in a right-aligned column ("ITEM …… 2")
        // instead of the confusing inline "x2 EA".
        $quantity = (float) ($line['quantity'] ?? 0);
        $delta = (float) ($line['round_delta'] ?? 0);
        $prefix = $showRunning && $delta > 0 && abs($delta - $quantity) < 0.000001 ? '(R) ' : '';
        $suffix = $showRunning && $delta > 0 && $delta < $quantity
            ? ' (R +' . $this->quantity($delta) . ')'
            : '';
        $unit = !empty($line['unit_code']) ? ' ' . $line['unit_code'] : '';

        $right = $this->quantity($quantity) . $unit;
        $left = mb_substr($prefix . strtoupper((string) ($line['product_name'] ?? 'ITEM')) . $suffix, 0, 41 - mb_strlen($right));

        return $this->columns($left, $right, 42);
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
