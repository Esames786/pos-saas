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
                $rows[] = array_values(array_filter([$labelCol, $ordersCol, $kind === 'waiters' ? 'Sales' : 'Net Qty', $kind === 'waiters' ? null : 'Net'], fn ($c) => $c !== null));
                foreach ($lines as $line) {
                    $rows[] = $kind === 'waiters'
                        ? [$line['label'], $line['orders'], $line['grand_total']]
                        : array_values(array_filter([$line['label'], $line['orders'] ?? null, $line['net_qty'], $line['net']], fn ($c) => $c !== null));
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
        $rows = [['Category', 'Child', 'Orders', 'Sold Qty', 'Returned Qty', 'Net Qty', 'Gross', 'Discount', 'Tax', 'Returns', 'Net']];
        foreach ($this->engine->byCategory($f) as $root) {
            $rows[] = [$root['name'], '', $root['orders'], $root['sold_qty'], $root['returned_qty'], $root['net_qty'], $root['gross'], $root['discount'], $root['tax'], $root['returns_amount'], $root['net']];
            foreach ($root['children'] as $c) {
                $rows[] = ['', $c['name'], $c['orders'], $c['sold_qty'], $c['returned_qty'], $c['net_qty'], $c['gross'], $c['discount'], $c['tax'], $c['returns_amount'], $c['net']];
            }
        }

        return $this->csv($rows);
    }

    private function itemsCsv(array $f): string
    {
        $rows = [['Item', 'Variant', 'Category', 'Sold Qty', 'Returned Qty', 'Net Qty', 'Gross', 'Discount', 'Tax', 'Net']];
        foreach ($this->engine->byItem($f) as $r) {
            $rows[] = [$r->item, $r->variant, $r->category, $r->sold_qty, $r->returned_qty, $r->net_qty, $r->gross, $r->discount, $r->tax, $r->net];
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
            fputcsv($fh, [$r->sale_date, $r->sale_no, $r->sale_status, $r->order_type, $r->terminal, $r->shift_id, $r->cashier, $r->waiter, $r->item, $r->variant, $r->category, $r->quantity, $r->returned_quantity, $r->unit_price, $r->gross, $r->discount_amount, $r->tax_amount, $r->line_total, $r->delivery_charge_amount, $r->grand_total]);
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
