<?php

namespace App\Services\Reports;

use Dompdf\Dompdf;
use Dompdf\Options;

/** Builds the same A4 Sales Report Centre document for browser print and scheduled email. */
class SalesReportDocumentService
{
    public function __construct(private readonly SalesReportEngine $engine) {}

    /**
     * QUICK-REPORT-OPEN-BILLS-1: `$withOpen` sirf POS ki Quick Report bhejti hai. Jo caller ise na
     * bheje (Report Center, nightly email, Z Report) uske data me `open` ki kunji MOJOOD HI NAHI
     * hoti — aur renderer wo satrein `isset()` ke peeche rakhta hai, is liye un ke liye wo satrein
     * banti bhi nahi. Paid ka hisab kisi soorat me nahi badalta: `openBills()` ek ALAG query hai.
     */
    public function data(array $filters, array $sections, bool $embedded = false, bool $withOpen = false): array
    {
        $pick = fn (string $key, callable $loader) => in_array($key, $sections, true) ? $loader() : null;
        $summary = $this->engine->overview($filters);

        return [
            'mode' => 'a4',
            'paper' => '80mm',
            'filters' => $filters,
            'sections' => $sections,
            // BRIDGE-DEALS-1: a section that leaves the deals out cannot close to NET SALES on
            // charges alone — it needs the deals named on their own line.
            'bridge' => $summary + ['deals_net' => $this->engine->dealsNet($filters)],
            'overview' => in_array('overview', $sections, true) ? $summary : null,
            'orderTypes' => $pick('order_types', fn () => $this->engine->byOrderType($filters)),
            'categories' => $pick('categories', fn () => $this->engine->byCategory($filters)),
            // DEAL-CATEGORY-1: Items no longer carries the deals — they have a section of their
            // own, and printed in both they would count the same money twice.
            'items' => $pick('items', fn () => $this->engine->byItem($filters, 'net', true)),
            // ITEMS-BY-CATEGORY-1: the same Items rows under their category heads — its own
            // section, so the nightly PDF and the thermal both carry it when it is ticked.
            'categoryItems' => $pick('category_items', fn () => $this->engine->byCategoryItems($filters)),
            'deals' => $pick('deals', fn () => $this->engine->byDeal($filters)),
            'waiters' => $pick('waiters', fn () => $this->engine->byWaiter($filters)),
            'combos' => $pick('order_type_combos', fn () => $this->engine->orderTypeCombos($filters)),
            'cancellations' => $pick('cancellations', fn () => $this->engine->cancellations($filters)),
            'cashBank' => $pick('cash_bank', fn () => $this->engine->cashBank($filters)),
            'open' => $withOpen ? $this->engine->openBills($filters) : null,
            'embedded' => $embedded,
        ];
    }

    public function pdf(array $filters, array $sections, bool $withOpen = false): string
    {
        $html = view('tenant.reports.center.print', $this->data($filters, $sections, true, $withOpen))->render();
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
