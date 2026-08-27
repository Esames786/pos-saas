<?php

namespace App\Services\Reports;

use Dompdf\Dompdf;
use Dompdf\Options;

/** Builds the same A4 Sales Report Centre document for browser print and scheduled email. */
class SalesReportDocumentService
{
    public function __construct(private readonly SalesReportEngine $engine) {}

    /**
     * Build the print.blade data array. `$select` (QUICK-REPORT-SEND-1) optionally narrows ONLY the
     * breakdown sections to chosen rows — categories by root id, items by product_id, waiters/order
     * types by their raw id — while overview / combos / cancellations / cash-bank stay full. This is a
     * DISPLAY post-filter (the engine still measures the whole day), so the headline totals are the
     * real day's totals and only the picked breakdown rows are shown. Empty select = everything (the
     * Report Center + scheduled-report callers pass nothing → identical output as before).
     *
     * @param  array{category_ids?:array,product_ids?:array,waiter_ids?:array,order_types?:array}  $select
     */
    public function data(array $filters, array $sections, bool $embedded = false, array $select = []): array
    {
        $pick = fn (string $key, callable $loader) => in_array($key, $sections, true) ? $loader() : null;
        $summary = $this->engine->overview($filters);

        $categories = $pick('categories', fn () => $this->engine->byCategory($filters));
        $items      = $pick('items', fn () => $this->engine->byItem($filters));
        $waiters    = $pick('waiters', fn () => $this->engine->byWaiter($filters));
        $orderTypes = $pick('order_types', fn () => $this->engine->byOrderType($filters));

        // ── Post-filter to the selected rows (only when a non-empty selection is given) ──
        $catIds     = array_values(array_filter(array_map('intval', $select['category_ids'] ?? [])));
        $prodIds    = array_values(array_filter(array_map('intval', $select['product_ids'] ?? [])));
        $waiterIds  = array_map('strval', array_filter($select['waiter_ids'] ?? [], fn ($v) => $v !== '' && $v !== null));
        $selOrderTy = array_map('strval', array_filter($select['order_types'] ?? [], fn ($v) => $v !== '' && $v !== null));

        if ($categories !== null && $catIds) {
            // byCategory returns a tree keyed by ROOT category; keep the selected roots (flat tenants =
            // one root per category, so this is exactly the picked categories).
            $categories = array_values(array_filter($categories, fn ($root) => in_array((int) ($root['id'] ?? 0), $catIds, true)));
        }
        if ($items !== null && $prodIds) {
            // byItem rows are stdClass with ->product_id.
            $items = array_values(array_filter($items, fn ($r) => in_array((int) ($r->product_id ?? 0), $prodIds, true)));
        }
        if ($waiters !== null && $waiterIds) {
            $waiters = array_values(array_filter($waiters, fn ($r) => in_array((string) ($r['id'] ?? ''), $waiterIds, true)));
        }
        if ($orderTypes !== null && $selOrderTy) {
            $orderTypes = array_values(array_filter($orderTypes, fn ($r) => in_array((string) ($r['id'] ?? ''), $selOrderTy, true)));
        }

        return [
            'mode' => 'a4',
            'paper' => '80mm',
            'filters' => $filters,
            'sections' => $sections,
            'bridge' => $summary,
            'overview' => in_array('overview', $sections, true) ? $summary : null,
            'orderTypes' => $orderTypes,
            'categories' => $categories,
            'items' => $items,
            'waiters' => $waiters,
            'combos' => $pick('order_type_combos', fn () => $this->engine->orderTypeCombos($filters)),
            'cancellations' => $pick('cancellations', fn () => $this->engine->cancellations($filters)),
            'cashBank' => $pick('cash_bank', fn () => $this->engine->cashBank($filters)),
            'embedded' => $embedded,
        ];
    }

    public function pdf(array $filters, array $sections, array $select = []): string
    {
        $html = view('tenant.reports.center.print', $this->data($filters, $sections, true, $select))->render();
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->setPaper('a4', 'portrait');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        return $pdf->output();
    }
}
