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

    /** ESC E n — emphasised (bold) on/off. */
    private const BOLD_ON  = "\x1B\x45\x01";
    private const BOLD_OFF = "\x1B\x45\x00";

    /**
     * GS ! n — character size. The low nibble is height-1, the high nibble is width-1, so
     * 0x00 is normal, 0x11 is double both, 0x22 is triple both.
     */
    private const SIZE_NORMAL = "\x1D\x21\x00";

    /** Characters a line holds at normal width. Halves at double width, thirds at triple. */
    private const COLS_80MM = 42;

    /**
     * THE LAYOUT SCREEN'S FONT SIZE NOW REACHES THE PRINTER.
     *
     * font_size / kot_font_size were CSS pixels for the browser preview and nothing else — the
     * ESC/POS payload emitted no size or emphasis bytes at all, so every ticket printed at Font A
     * single size no matter what the setting said. Khatri's kitchen could not read the tickets and
     * changing the setting appeared to do nothing, because for the thermal printer it did.
     *
     * The px value now selects a printer scale. The bands start scaling ABOVE the seeded defaults
     * (receipt 12, KOT 14), so no existing tenant's output changes until someone raises the value.
     */
    private function scaleFor(?int $px): array
    {
        return match (true) {
            $px === null || $px <= 14 => ['w' => 1, 'h' => 1],
            $px <= 17                 => ['w' => 1, 'h' => 2],   // twice as tall, full 42 columns
            $px <= 20                 => ['w' => 2, 'h' => 2],   // twice as tall AND wide, 21 columns
            default                   => ['w' => 3, 'h' => 3],   // 14 columns
        };
    }

    /** GS ! byte for a width/height multiplier pair (1-8 each). */
    private function sizeCommand(int $w, int $h): string
    {
        $n = ((max(1, min(8, $w)) - 1) << 4) | (max(1, min(8, $h)) - 1);

        return "\x1D\x21" . chr($n);
    }

    /**
     * Emit text at a scale, wrapping to the width that scale actually leaves.
     *
     * Wrapping is the whole point: at double width the printer only fits 21 characters, and it
     * does NOT wrap for you — it prints the overflow on the next line mid-word, or drops it,
     * depending on the model. A ticket that reads "BEEF CHANGEZI PULAO S" with the rest missing
     * is worse than a small one.
     */
    private function scaled(string $text, array $scale, bool $bold = false, bool $center = false, int $baseWidth = self::COLS_80MM): string
    {
        $width = max((int) floor($baseWidth / max(1, $scale['w'])), 8);
        $lines = [];

        foreach (preg_split('/\R/', $text) as $paragraph) {
            $wrapped = wordwrap(trim($paragraph), $width, "\n", true);
            foreach (explode("\n", $wrapped) as $line) {
                $lines[] = $center ? $this->center($line, $width) : $line;
            }
        }

        $body = implode("\n", $lines) . "\n";

        // Always return to normal + bold off, so one scaled block can never leak into the next.
        return $this->sizeCommand($scale['w'], $scale['h'])
            . ($bold ? self::BOLD_ON : '')
            . $body
            . ($bold ? self::BOLD_OFF : '')
            . self::SIZE_NORMAL;
    }

    /**
     * Emit ALREADY-LAID-OUT text at a scale — size + bold bytes only, no wrap and no trim.
     *
     * The column builders (itemColumns / qtyItemColumns) have already wrapped to the exact width
     * the scale leaves and set hanging indents. scaled() would trim those indents and re-wrap, so
     * pre-formatted column blocks come through here instead. The width the caller wrapped to must
     * be `floor(42 / scale['w'])`.
     */
    private function sized(string $text, array $scale, bool $bold = false): string
    {
        return $this->sizeCommand($scale['w'], $scale['h'])
            . ($bold ? self::BOLD_ON : '')
            . $text
            . ($bold ? self::BOLD_OFF : '')
            . self::SIZE_NORMAL;
    }

    /** Characters a line holds at the given scale (42 at normal width, 21 at double, 14 at triple). */
    private function scaledWidth(array $scale): int
    {
        return max((int) floor(self::COLS_80MM / max(1, $scale['w'])), 8);
    }

    /**
     * A sub-item line (variant / modifier / combo component / note) wrapped to the scale's width
     * with its leading indent kept on every wrapped line — so "+ Extra cheese and jalapenos" reads
     * as an indented continuation, not text that snapped back to the margin. Emitted, not returned
     * raw, so the caller just concatenates.
     */
    private function subRow(string $text, array $scale, bool $bold = false): string
    {
        preg_match('/^(\s*)/', $text, $m);
        $indent = $m[1];
        $width = max($this->scaledWidth($scale) - mb_strlen($indent), 6);

        $body = '';
        foreach (explode("\n", wordwrap(ltrim($text), $width, "\n", true)) as $l) {
            $body .= $indent . $l . "\n";
        }

        return $this->sized($body, $scale, $bold);
    }

    /**
     * Unit suffix for a printed quantity. Piece units ("2 EA") are noise on a ticket — the number
     * alone is the count. Real measures (KG, LTR, GM…) still print so staff can weigh correctly.
     */
    private const PIECE_UNITS = ['EA', 'EACH', 'PC', 'PCS', 'PIECE', 'PIECES', 'NOS', 'NO', 'UNIT', 'UNITS', 'QTY'];

    private function unitSuffix(?string $unitCode): string
    {
        $code = trim((string) $unitCode);

        return ($code === '' || in_array(strtoupper($code), self::PIECE_UNITS, true)) ? '' : ' ' . $code;
    }

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
        // Same scale rule as the KOT — the reminder is read in the kitchen, not at the till.
        // Taken from the payload snapshot so a reprint months later still renders as it did.
        $big = $this->scaleFor(isset($layout['font_size']) ? (int) $layout['font_size'] : null);
        $rule = str_repeat('-', max((int) floor(self::COLS_80MM / max(1, $big['w'])), 8));

        $out .= $this->center('*** REMINDER ***') . "\n";
        $out .= $this->scaled((string) ($payload['heading'] ?? 'REMINDER'), $big, true, true);
        if (!in_array($eventType, ['cancelled_order', 'cancelled_updated_order'], true)) {
            $out .= $this->center('REVISION ' . $revision) . "\n";
        }
        if (!empty($payload['is_reprint'])) {
            $out .= $this->center('DUPLICATE ' . $copyNo) . "\n";
        }
        // PRINT-FORMAT-PARITY-1: order type leads the ticket (kitchen reads it first).
        $out .= $this->scaled('** ' . strtoupper(str_replace('_', ' ', (string) ($payload['order_type'] ?? 'SALE'))) . ' **', $big, true, true);
        if ($layout['show_order_no'] ?? true) {
            $out .= $this->center((string) ($payload['sale_no'] ?? '')) . "\n";
        }
        $out .= $rule . "\n";
        if (($layout['show_table_info'] ?? true) && !empty($payload['table'])) {
            $out .= $this->scaled('TABLE: ' . $payload['table'], $big, true);
        }
        if (($layout['show_table_info'] ?? true) && !empty($payload['waiter'])) {
            $out .= $this->scaled('WAITER: ' . strtoupper((string) $payload['waiter']), $big, true);
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

        // Sub-detail is one step below the item: taller than normal so it reads, never double
        // width, so a modifier can never wrap into an unreadable stub.
        $sub = ['w' => 1, 'h' => $big['h']];

        // Qty | Item column header (matches the KOT), then the items.
        $out .= $this->sized($this->qtyItemColumns('QTY', 'ITEM', $this->scaledWidth($big)), $big, true);
        $out .= $rule . "\n";

        if (!empty($payload['cancelled_lines'])) {
            $out .= $this->scaled('CANCELLED:', $big, true);
            foreach ($payload['cancelled_lines'] as $line) {
                $out .= $this->reminderLine($line, false, $big);
            }
            $out .= $rule . "\n";
            $out .= $this->scaled('REMAINING ORDER:', $big, true);
        }

        $lines = collect($payload['lines'] ?? []);
        $topLevel = $lines->filter(fn ($line) => empty($line['parent_line_id']));
        if ($topLevel->isEmpty()) {
            $out .= $this->scaled('NO REMAINING ITEMS', $big, true);
        }
        foreach ($topLevel as $line) {
            $out .= $this->reminderLine($line, $revision > 1, $big);
            foreach ($lines->where('parent_line_id', $line['line_id'] ?? null) as $component) {
                $out .= $this->reminderLine($component, $revision > 1, $sub, '  - ');
                foreach (($component['modifiers'] ?? []) as $modifier) {
                    if (!empty($modifier['name'])) { $out .= $this->subRow('    + ' . $modifier['name'], $sub); }
                }
                if (!empty($component['kitchen_note'])) { $out .= $this->subRow('    NOTE: ' . $component['kitchen_note'], $sub, true); }
            }
            foreach (($line['modifiers'] ?? []) as $modifier) {
                if (!empty($modifier['name'])) {
                    $out .= $this->subRow('  + ' . $modifier['name'], $sub);
                }
            }
            if (!empty($line['kitchen_note'])) {
                $out .= $this->subRow('  NOTE: ' . $line['kitchen_note'], $sub, true);
            }
            $out .= $rule . "\n";   // separator after each reminder item, matching the KOT
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

        // A RECEIPT SCALES IN HEIGHT ONLY.
        //
        // Every money row here is a 42-column two-column layout — label left, amount right. Double
        // WIDTH halves that to 21 and the amounts would no longer line up under one another, which
        // on a customer's bill is worse than small text. Taller characters cost nothing: the line
        // still holds 42 characters, so Subtotal / Discount / Total stay in their column.
        $tall = ['w' => 1, 'h' => $this->scaleFor($layout?->font_size)['h']];

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
        if ($show('show_order_type')) {
            $out .= $this->center('** ' . strtoupper(str_replace('_', ' ', $sale->order_type ?? 'SALE')) . ' **') . "\n";
            $out .= str_repeat('-', 42) . "\n";
        }
        if ($show('show_order_no')) {
            $out .= "Receipt: {$sale->sale_no}\n";
        }
        $tz = $this->printTz($sale);
        $out .= 'Date: ' . ($sale->sale_date ? $sale->sale_date->copy()->timezone($tz)->format('d/m/Y h:i A') : '') . "\n";
        if ($show('show_cashier_name')) {
            $out .= 'Cashier: ' . ($sale->createdBy?->name ?? '-') . "\n";
        }
        // Customer name AND contact number on the bill (default ON for receipts) — the delivery
        // rider and the counter both need the number; typed walk-in details count too.
        // BOLD: everything the delivery rider reads off the bill at the door — who, which number,
        // which address. The cashier's and system's own reference lines stay normal weight.
        if ($show('show_customer_name')) {
            $customerName = $sale->customer_name ?: $sale->customer?->name;
            $customerPhone = $sale->customer_phone ?: $sale->customer?->phone;
            if ($customerName) {
                $out .= self::BOLD_ON . 'Customer: ' . $customerName . self::BOLD_OFF . "\n";
            }
            if ($customerPhone) {
                $out .= self::BOLD_ON . 'Phone: ' . $customerPhone . self::BOLD_OFF . "\n";
            }
        }

        if ($show('show_table_info')) {
            if ($sale->restaurantTable) {
                $out .= 'Table: ' . $sale->restaurantTable->table_no . "\n";
            }
            if ($sale->restaurantWaiter) {
                $out .= 'Waiter: ' . $sale->restaurantWaiter->name . "\n";
            }
        }
        if ($show('show_delivery_details')) {
            if ($sale->deliveryChannel) {
                $out .= 'Channel: ' . $sale->deliveryChannel->name . "\n";
            }
            if ($sale->deliveryRider) {
                $out .= self::BOLD_ON . 'Rider: ' . $sale->deliveryRider->name . self::BOLD_OFF . "\n";
            }
            if ($sale->delivery_address) {
                $out .= self::BOLD_ON . 'Deliver to: ' . $sale->delivery_address . self::BOLD_OFF . "\n";
            }
        }
        if ($show('show_vehicle_number') && $sale->vehicle_number) {
            $out .= self::BOLD_ON . 'Vehicle: ' . $sale->vehicle_number . self::BOLD_OFF . "\n";
        }

        $w = $this->scaledWidth($tall);   // 42 (receipts scale height-only)

        // Measure the numeric columns against the WHOLE table first (short money — no forced .00),
        // so whole-rupee prices shrink the numbers and hand the freed width to the item name.
        $printable = $sale->lines->filter(fn ($l) => ($l->line_kind ?? 'standard') !== 'component');
        $measure = $printable->map(fn ($l) => [
            $this->quantity((float) $l->quantity),
            $this->money((float) $l->unit_price),
            $this->money((float) $l->line_total),
        ])->all();
        [$nameW, $qtyW, $rateW, $amountW] = $this->receiptColumnWidths($measure, $w);

        $out .= str_repeat('-', 42) . "\n";
        // COLUMN HEADER — Item | Qty | Rate | Amount, the layout the client's bill reads by.
        $out .= $this->sized($this->itemColumns('Item', 'Qty', 'Rate', 'Amount', $nameW, $qtyW, $rateW, $amountW), $tall, true);
        $out .= str_repeat('-', 42) . "\n";

        foreach ($sale->lines as $line) {
            if (($line->line_kind ?? 'standard') === 'component') {
                continue;
            }

            // Item row in columns: description (wraps) | qty | unit rate | line amount. The numbers
            // keep their own columns, so a long name never collides with them.
            $out .= $this->sized($this->itemColumns(
                (string) ($line->product_name ?? ''),
                $this->quantity((float) $line->quantity),
                $this->money((float) $line->unit_price),
                $this->money((float) $line->line_total),
                $nameW, $qtyW, $rateW, $amountW
            ), $tall);

            if ($line->variant_name) {
                $out .= $this->sized('  (' . $line->variant_name . ')' . "\n", $tall);
            }
            if ($line->kitchen_note) {
                $out .= "  * {$line->kitchen_note}\n";
            }

            // Modifiers and combo components sit UNDER the item as name-only sub-rows (no price
            // columns — a modifier's delta rides in the parent's amount, a component has no price).
            foreach ($this->lineModifiers($line) as $modifier) {
                $label = '  + ' . $modifier['name'];
                $delta = (float) ($modifier['price_delta'] ?? 0);
                if ($delta !== 0.0) {
                    $label .= ' (' . ($delta > 0 ? '+' : '') . number_format($delta, 2) . ')';
                }
                $out .= $this->sized($label . "\n", $tall);
            }

            foreach ($sale->lines->where('parent_sales_order_line_id', $line->id) as $component) {
                $componentQty = $this->quantity((float) $component->quantity) . $this->unitSuffix($component->unit_code);
                $out .= $this->sized('  - ' . $componentQty . ' x ' . ($component->product_name ?? '') . "\n", $tall);
            }

            // A separator after each item (with its modifiers/components) so rows read as boxes.
            $out .= str_repeat('-', 42) . "\n";
        }

        $out .= $this->scaled($this->columns('Subtotal', $this->money((float) $sale->subtotal), 42), $tall);

        if ((float) $sale->discount_amount > 0) {
            $out .= $this->scaled($this->columns('Discount', '-' . $this->money((float) $sale->discount_amount), 42), $tall);
        }
        if ((float) $sale->tax_amount > 0) {
            $out .= $this->scaled($this->columns('Tax', $this->money((float) $sale->tax_amount), 42), $tall);
        }
        if ((float) ($sale->service_charge_amount ?? 0) > 0) {
            $out .= $this->scaled($this->columns('Service Charge', $this->money((float) $sale->service_charge_amount), 42), $tall);
        }
        if ((float) ($sale->delivery_charge_amount ?? 0) > 0) {
            $out .= $this->scaled($this->columns('Delivery Charge', $this->money((float) $sale->delivery_charge_amount), 42), $tall);
        }
        if ((float) ($sale->tip_amount ?? 0) > 0) {
            $out .= $this->scaled($this->columns('Tip', $this->money((float) $sale->tip_amount), 42), $tall);
        }

        // The amount the customer actually owes is the one line they look for — always bold.
        $out .= $this->scaled($this->columns('TOTAL', $this->money((float) $sale->grand_total), 42), $tall, true);
        $out .= $this->scaled($this->columns('Paid', $this->money((float) $sale->paid_amount), 42), $tall);
        $out .= $this->scaled($this->columns('Change', $this->money((float) $sale->change_amount), 42), $tall);

        $out .= str_repeat('-', 42) . "\n";

        if ($show('show_payment_breakdown')) {
            foreach ($sale->payments as $payment) {
                $methodName = $payment->method?->name ?? ucfirst($payment->payment_method ?? 'Payment');
                // Show the physical cash handed over (tendered) when captured — "Cash 5,000.00 /
                // Change 1,400.00" tells the drawer story; applied-only amounts hide the change.
                $out .= $this->columns($methodName, $this->money((float) ($payment->tendered_amount ?? $payment->amount)), 42) . "\n";
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

        // What the kitchen READS gets the configured scale; order numbers and the printed-at
        // stamp stay small, because they are reference data nobody cooks from.
        $big = $this->scaleFor($layout?->kot_font_size);
        $rule = str_repeat('-', max((int) floor(self::COLS_80MM / max(1, $big['w'])), 8));

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
        // The heading (KOT / CANCEL KOT / ADDITION KOT) reads at the same big scale as the order
        // type and category below it — a cancel ticket the kitchen can read across the pass.
        $out .= $this->scaled($heading, $big, true, true);
        if ($eventType === 'duplicate') {
            $out .= $this->center('DUPLICATE ' . max($copyNo, 1)) . "\n";
        }
        $out .= $this->scaled('** ' . strtoupper(str_replace('_', ' ', $sale->order_type ?? 'SALE')) . ' **', $big, true, true);
        // One ticket per category — name the category so the station knows the slip is theirs.
        if (! empty($payload['kot_category'])) {
            // The [ ] decoration costs four characters. At double width that is a fifth of the
            // line, and a category that nearly fits wraps its closing bracket onto a line of its
            // own. Keep the brackets only while they earn their space.
            $category = strtoupper((string) $payload['kot_category']);
            $bracketed = '[ ' . $category . ' ]';
            $catWidth = (int) floor(self::COLS_80MM / max(1, $big['w']));
            $out .= $this->scaled(mb_strlen($bracketed) <= $catWidth ? $bracketed : $category, $big, true, true);
        }
        // The browser KOT always honoured show_branch_name; the paper never printed it at all, so
        // the toggle changed the preview and not the ticket. Same gate on both now.
        if ($show('show_branch_name') && $sale->branch?->name) {
            $out .= $this->center($sale->branch->name) . "\n";
        }
        if ($show('show_order_no')) {
            $out .= $this->center($sale->sale_no ?? '') . "\n";
        }
        $out .= $rule . "\n";

        if ($show('show_table_info')) {
            if ($sale->restaurantTable) {
                $out .= $this->scaled('TABLE: ' . $sale->restaurantTable->table_no, $big, true);
            }
            if ($sale->restaurantWaiter) {
                $out .= $this->scaled('WAITER: ' . strtoupper($sale->restaurantWaiter->name), $big, true);
            }
        }
        if ($show('show_cashier_name') && $sale->createdBy) {
            $out .= 'CASHIER: ' . $sale->createdBy->name . "\n";
        }
        if ($show('show_vehicle_number') && $sale->vehicle_number) {
            $out .= $this->scaled('VEHICLE: ' . $sale->vehicle_number, $big, true);
        }
        // The old ticket led with a big wall-clock time; the date is reference and stays small.
        $now = now()->timezone($this->printTz($sale));
        $out .= $this->scaled('TIME: ' . $now->format('h:i A'), $big, true);
        $out .= $now->format('D d-M-Y') . "\n";
        $out .= $rule . "\n";
        // Qty | Item column header (no price — a kitchen ticket carries none).
        $out .= $this->sized($this->qtyItemColumns('QTY', 'ITEM', $this->scaledWidth($big)), $big, true);
        $out .= $rule . "\n";

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
            $kotQty = $this->quantity($qtyToPrint) . $this->unitSuffix($line->unit_code);
            $name = $runningPrefix . strtoupper($line->product_name ?? '');

            // COLUMN approach — Qty | Item — with the name wrapping under its column. No price:
            // a kitchen ticket carries none.
            $out .= $this->sized($this->qtyItemColumns($kotQty, $name, $this->scaledWidth($big)), $big, true);

            // Variant, modifiers and the cook's note stay one step below the item: always taller
            // than normal so they are readable, never double width, so they never wrap.
            $sub = ['w' => 1, 'h' => $big['h']];
            if ($line->variant_name) {
                $out .= $this->subRow('  ' . $line->variant_name, $sub);
            }
            foreach ($this->lineModifiers($line) as $modifier) {
                $out .= $this->subRow('  + ' . $modifier['name'], $sub);
            }
            if ($line->kitchen_note) {
                $out .= $this->subRow('  NOTE: ' . $line->kitchen_note, $sub, true);
            }
            $out .= $rule . "\n";   // separator after each KOT item, so rows read as boxes
        }

        if ($sale->notes) {
            $out .= $this->scaled('ORDER NOTE: ' . $sale->notes, ['w' => 1, 'h' => $big['h']], true);
            $out .= $rule . "\n";
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

    /**
     * A receipt item row laid out in COLUMNS — Item | Qty | Rate | Amount — the way the client's
     * bill reads. The description column wraps within its width and the numeric columns stay
     * right-aligned on the first line; continuation lines carry only the wrapped name, so the
     * numbers never collide and a long name never squeezes them. 42 Font-A columns; the caller
     * applies height scaling. Pass rate/amount empty for a header or a no-price sub-row.
     */
    /** Multibyte-safe str_pad (product names may carry non-ASCII); pads on display width. */
    private function mbStrPad(string $s, int $len, string $pad = ' ', int $type = STR_PAD_RIGHT): string
    {
        $gap = $len - mb_strlen($s);
        if ($gap <= 0) {
            return $s;
        }
        $fill = str_repeat($pad, $gap);

        return $type === STR_PAD_LEFT ? $fill . $s : $s . $fill;
    }

    /**
     * Money for a receipt, WITHOUT a forced ".00" — a whole-rupee price prints "3,500", not
     * "3,500.00", so the numeric columns shrink and the item name gets the freed width. Genuine
     * paisa still print ("449.50"): nothing is rounded, only trailing zeros are dropped, so the
     * bill still adds up exactly.
     */
    private function money(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2), '0'), '.');
    }

    /**
     * One item row of the receipt column table, with the numeric column WIDTHS passed in — the
     * caller measures the whole table's qty/rate/amount first and hands the widths here, so every
     * row lines up and the name column claims all the width the (now shorter) numbers don't use.
     */
    private function itemColumns(string $name, string $qty, string $rate, string $amount, int $nameW, int $qtyW, int $rateW, int $amountW): string
    {
        $lines = explode("\n", wordwrap(trim($name), max($nameW, 6), "\n", true));
        $head = array_shift($lines);

        $row = $this->mbStrPad($head, $nameW) . ' '
             . $this->mbStrPad($qty, $qtyW, ' ', STR_PAD_LEFT) . ' '
             . $this->mbStrPad($rate, $rateW, ' ', STR_PAD_LEFT) . ' '
             . $this->mbStrPad($amount, $amountW, ' ', STR_PAD_LEFT);
        $out = rtrim($row) . "\n";
        foreach ($lines as $cont) {
            $out .= $cont . "\n";   // continuation: wrapped name only, numeric columns already set
        }

        return $out;
    }

    /**
     * Measure the numeric column widths a receipt's item table needs: the widest qty / rate /
     * amount across every row plus the header labels, so the columns are exactly as wide as the
     * data (a whole-rupee shop gets narrow numbers and a wide name column). Returns
     * [nameW, qtyW, rateW, amountW] summing (with 3 gaps) to $width.
     */
    private function receiptColumnWidths(array $rows, int $width): array
    {
        $qtyW = mb_strlen('Qty');
        $rateW = mb_strlen('Rate');
        $amountW = mb_strlen('Amount');
        foreach ($rows as [$q, $r, $a]) {
            $qtyW = max($qtyW, mb_strlen($q));
            $rateW = max($rateW, mb_strlen($r));
            $amountW = max($amountW, mb_strlen($a));
        }
        // Keep the name column readable even if a huge number appears; it wraps if it must.
        $nameW = max($width - $qtyW - $rateW - $amountW - 3, 8);

        return [$nameW, $qtyW, $rateW, $amountW];
    }

    /**
     * A KOT / Reminder item row in COLUMNS — Qty | Item — with NO price (kitchen tickets carry no
     * money). Qty sits in a narrow left column; the name wraps with a hanging indent so it reads
     * as a column even at double width.
     */
    private function qtyItemColumns(string $qty, string $name, int $width): string
    {
        $qtyW = min(3, max(1, $width - 6));
        $nameW = max($width - $qtyW - 1, 6);

        $lines = explode("\n", wordwrap(trim($name), $nameW, "\n", true));
        $head = array_shift($lines);

        $out = $this->mbStrPad($qty, $qtyW, ' ', STR_PAD_LEFT) . ' ' . $head . "\n";
        foreach ($lines as $cont) {
            $out .= str_repeat(' ', $qtyW + 1) . $cont . "\n";   // hanging indent under the name column
        }

        return $out;
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

    /**
     * One reminder item, already scaled and terminated with a newline.
     *
     * At normal width the quantity keeps its right-aligned column ("ITEM …… 2"). At double width
     * only 21 characters fit, so the count leads instead — the same trade the KOT makes.
     */
    private function reminderLine(array $line, bool $showRunning, ?array $scale = null, string $indent = ''): string
    {
        $scale ??= ['w' => 1, 'h' => 1];
        $quantity = (float) ($line['quantity'] ?? 0);
        $delta = (float) ($line['round_delta'] ?? 0);
        $prefix = $showRunning && $delta > 0 && abs($delta - $quantity) < 0.000001 ? '(R) ' : '';
        $suffix = $showRunning && $delta > 0 && $delta < $quantity
            ? ' (R +' . $this->quantity($delta) . ')'
            : '';
        $unit = $this->unitSuffix($line['unit_code'] ?? null);

        $qty = $this->quantity($quantity) . $unit;
        $name = $prefix . strtoupper((string) ($line['product_name'] ?? 'ITEM')) . $suffix;

        // Top-level items use the COLUMN approach — Qty | Item, matching the KOT (no price; a
        // reminder is non-fiscal). Indented rows (combo components) render as name-only sub-rows.
        if ($indent !== '') {
            return $this->subRow($indent . $qty . ' x ' . $name, $scale, true);
        }

        return $this->sized($this->qtyItemColumns($qty, $name, $this->scaledWidth($scale)), $scale, true);
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
            return $moment->format('d/m/Y h:i A');
        } catch (\Throwable) {
            return $timestamp;
        }
    }
}
