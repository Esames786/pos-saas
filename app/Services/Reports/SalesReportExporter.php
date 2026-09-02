<?php

namespace App\Services\Reports;

/**
 * SALES REPORT CENTER (spec Y/D6) — CSV section renderer. CSV (BOM'd, Excel-friendly) is the
 * repository-supported export today; this class is ALSO the seam where native XLSX/PDF drivers plug
 * in once the composer dependencies are approved. Every section renders from the SAME engine output
 * the screen shows — exports can never diverge from the UI numbers.
 */
class SalesReportExporter
{
    public function __construct(private readonly SalesReportEngine $engine)
    {
    }

    /** @return array<string, string> section => CSV content (full filtered set, not the UI page) */
    public function sections(array $filters, array $sections): array
    {
        $out = [];
        foreach ($sections as $section) {
            $out[$section] = match ($section) {
                'overview' => $this->overviewCsv($filters),
                'categories' => $this->categoriesCsv($filters),
                'items' => $this->itemsCsv($filters),
                'category_items' => $this->categoryItemsCsv($filters),
                'deals' => $this->dealsCsv($filters),
                'waiters' => $this->dimensionCsv($this->engine->byWaiter($filters), 'Waiter'),
                'order_types' => $this->dimensionCsv($this->engine->byOrderType($filters), 'Order Type'),
                'order_type_combos' => $this->combosCsv($filters),
                'cancellations' => $this->cancellationsCsv($filters),
                'detailed' => $this->detailedCsv($filters),
                'cash_bank' => $this->cashBankCsv($filters),
                default => '',
            };
        }

        return array_filter($out);
    }

    private function combosCsv(array $f): string
    {
        $combos = $this->engine->orderTypeCombos($f);
        $rows = [];
        foreach (['categories' => ['Category', 'Orders'], 'items' => ['Item', null], 'waiters' => ['Waiter', 'Orders']] as $kind => [$labelCol, $ordersCol]) {
            foreach ($combos[$kind] as $orderType => $lines) {
                $rows[] = [strtoupper($orderType) . ' — BY ' . strtoupper(rtrim($labelCol, 's'))];
                $rows[] = $kind === 'waiters'
                    ? [$labelCol, 'Orders', 'Billed', 'Returns', 'Net']
                    : array_values(array_filter([$labelCol, $ordersCol, 'Sold Qty', 'Returned Qty', 'Net Qty', 'Sold Value', 'Returns', 'Net Value'], fn ($c) => $c !== null));
                foreach ($lines as $line) {
                    $rows[] = $kind === 'waiters'
                        ? [$line['label'], $line['orders'], $line['grand_total'], $line['returns_amount'], $line['net_sales']]
                        : array_values(array_filter([$line['label'], $line['orders'] ?? null, $line['sold_qty'], $line['returned_qty'], $line['net_qty'], $line['net'], $line['returns_amount'], $line['net_value']], fn ($c) => $c !== null));
                }
                $rows[] = [];
            }
        }

        return $this->csv($rows ?: [['No data']]);
    }

    private function cancellationsCsv(array $f): string
    {
        $c = $this->engine->cancellations($f);
        $rows = [['Item', 'Order Type', 'Reason', 'Events', 'Cancelled Qty']];
        foreach ($c['rows'] as $r) {
            $rows[] = [$r['item'], $r['order_type'], $r['reason'], $r['events'], $r['qty']];
        }
        $rows[] = ['TOTAL', '', '', $c['total_events'], $c['total_qty']];

        return $this->csv($rows);
    }

    private function csv(array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF"); // BOM — Excel-friendly (CsvStreamer convention)
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);

        return stream_get_contents($fh);
    }

    private function overviewCsv(array $f): string
    {
        $o = $this->engine->overview($f);
        $rows = [['Metric', 'Value']];
        foreach ($o as $k => $v) {
            if ($k === 'payments') {
                foreach ($v as $method => $amount) {
                    $rows[] = ['Payments: ' . $method, $amount];
                }

                continue;
            }
            $rows[] = [ucwords(str_replace('_', ' ', $k)), $v];
        }

        return $this->csv($rows);
    }

    private function categoriesCsv(array $f): string
    {
        $rows = [['Category', 'Child', 'Orders', 'Sold Qty', 'Returned Qty', 'Net Qty', 'Gross', 'Discount', 'Tax', 'Sold Value', 'Returns', 'Net Value']];
        foreach ($this->engine->byCategory($f) as $root) {
            $rows[] = [$root['name'], '', $root['orders'], $root['sold_qty'], $root['returned_qty'], $root['net_qty'], $root['gross'], $root['discount'], $root['tax'], $root['net'], $root['returns_amount'], $root['net_value']];
            foreach ($root['children'] as $c) {
                $rows[] = ['', $c['name'], $c['orders'], $c['sold_qty'], $c['returned_qty'], $c['net_qty'], $c['gross'], $c['discount'], $c['tax'], $c['net'], $c['returns_amount'], $c['net_value']];
            }
        }

        return $this->csv($rows);
    }

    private function itemsCsv(array $f): string
    {
        $rows = [['Item', 'Variant', 'Category', 'Sold Qty', 'Returned Qty', 'Net Qty', 'Gross', 'Discount', 'Tax', 'Sold Value', 'Returns', 'Net Value']];
        // DEAL-CATEGORY-1: deals export in a sheet of their own — here they would count twice.
        foreach ($this->engine->byItem($f, 'net', true) as $r) {
            $rows[] = [$r->item, $r->variant, $r->category, $r->sold_qty, $r->returned_qty, $r->net_qty, $r->gross, $r->discount, $r->tax, $r->net, $r->returns_amount, $r->net_value];
        }

        return $this->csv($rows);
    }

    /**
     * ITEMS-BY-CATEGORY-1: the same item rows as `itemsCsv`, but each one carrying the head it
     * belongs under, plus a subtotal line per head and per sub-head.
     *
     * A spreadsheet cannot indent, so the shape is carried in a Level column — HEAD / SUB-HEAD /
     * ITEM — which also lets the reader filter to just the heads and get the summary on its own.
     */
    private function categoryItemsCsv(array $f): string
    {
        $rows = [['Level', 'Head', 'Sub-Head', 'Item', 'Variant', 'Sold Qty', 'Returned Qty', 'Net Qty', 'Gross', 'Discount', 'Tax', 'Sold Value', 'Returns', 'Net Value']];

        foreach ($this->engine->byCategoryItems($f) as $head) {
            $rows[] = ['HEAD', $head['head'], '', '', '', $head['sold_qty'], $head['returned_qty'], $head['net_qty'],
                $head['gross'], $head['discount'], $head['tax'], $head['net'], $head['returns_amount'], $head['net_value']];

            foreach ($head['groups'] as $group) {
                $sub = $head['nested'] ? $group['name'] : '';
                if ($head['nested']) {
                    $rows[] = ['SUB-HEAD', $head['head'], $group['name'], '', '', $group['sold_qty'], $group['returned_qty'], $group['net_qty'],
                        $group['gross'], $group['discount'], $group['tax'], $group['net'], $group['returns_amount'], $group['net_value']];
                }
                foreach ($group['items'] as $item) {
                    $rows[] = ['ITEM', $head['head'], $sub, $item['item'], $item['variant'], $item['sold_qty'], $item['returned_qty'], $item['net_qty'],
                        $item['gross'], $item['discount'], $item['tax'], $item['net'], $item['returns_amount'], $item['net_value']];
                }
            }
        }

        return $this->csv($rows);
    }

    /** DEAL-CATEGORY-1: one row per deal under its own head — the shape the old software prints. */
    private function dealsCsv(array $f): string
    {
        $rows = [['Head', 'Deal', 'Orders', 'Sold Qty', 'Returned Qty', 'Net Qty', 'Gross', 'Discount', 'Sold Value', 'Returns', 'Net Value']];
        foreach ($this->engine->byDeal($f) as $r) {
            $rows[] = [$r['head'], $r['deal'], $r['orders'], $r['sold_qty'], $r['returned_qty'],
                $r['net_qty'], $r['gross'], $r['discount'], $r['net'], $r['returns_amount'], $r['net_value']];
        }

        return $this->csv($rows);
    }

    private function dimensionCsv(array $data, string $label): string
    {
        $rows = [[$label, 'Orders', 'Sold Qty', 'Returned Qty', 'Net Qty', 'Gross', 'Discount', 'Tax', 'Service Charge', 'Delivery Charge', 'Grand Total', 'Returns', 'Net Sales']];
        foreach ($data as $r) {
            $rows[] = [$r['label'], $r['orders'], $r['sold_qty'], $r['returned_qty'], $r['net_qty'], $r['gross'], $r['discount'], $r['tax'], $r['service_charge'], $r['delivery_charge'], $r['grand_total'], $r['returns_amount'], $r['net_sales']];
        }

        return $this->csv($rows);
    }

    private function detailedCsv(array $f): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['Date/Time', 'Sale No', 'Status', 'Order Type', 'Terminal', 'Shift', 'Cashier', 'Waiter', 'Item', 'Variant', 'Category', 'Qty', 'Returned', 'Rate', 'Gross', 'Discount', 'Tax', 'Line Net', 'Delivery Charge', 'Sale Grand Total']);
        // FULL filtered set, chunked — never the UI page, never one giant array.
        foreach ($this->engine->detailedQuery($f)->cursor() as $r) {
            // Same conversion as the on-screen table — an exported sheet that disagreed with the
            // screen (or the receipt) by five hours would be worse than either alone.
            $saleDate = app(\App\Support\TenantClock::class)
                ->format($r->sale_date, 'Y-m-d H:i', $r->sale_timezone ?? null);
            fputcsv($fh, [$saleDate, $r->sale_no, $r->sale_status, $r->order_type, $r->terminal, $r->shift_id, $r->cashier, $r->waiter, $r->item, $r->variant, $r->category, $r->quantity, $r->returned_quantity, $r->unit_price, $r->gross, $r->discount_amount, $r->tax_amount, $r->line_total, $r->delivery_charge_amount, $r->grand_total]);
        }
        rewind($fh);

        return stream_get_contents($fh);
    }

    private function cashBankCsv(array $f): string
    {
        $cb = $this->engine->cashBank($f);
        $rows = [['Cash & Bank Movement (money position — NOT sales revenue)', '']];
        $rows[] = ['Opening Cash (float — never revenue)', $cb['shifts']['opening_cash'] ?? 0];
        foreach ($cb['method_money'] as $method => $amount) {
            $rows[] = ['Sales received via ' . $method, $amount];
        }
        $rows[] = ['Expected Cash (opening + cash sales − cash refunds)', $cb['expected_cash_formula']];
        $rows[] = ['Counted Cash', $cb['shifts']['counted_cash'] ?? 0];
        $rows[] = ['Cash Variance', $cb['shifts']['cash_variance'] ?? 0];
        $rows[] = ['', ''];
        $rows[] = ['Movement', 'Direction', 'Account Type', 'Amount'];
        foreach ($cb['movements'] as $m) {
            $rows[] = [$m['label'], $m['direction'], $m['account_type'], $m['amount']];
        }
        $rows[] = ['Net Cash Movement', '', '', $cb['net_cash_movement']];
        $rows[] = ['Net Bank Movement', '', '', $cb['net_bank_movement']];

        return $this->csv($rows);
    }
}
