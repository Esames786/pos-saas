<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\ReceiptLayoutSetting;
use App\Models\Tenant\SalesOrder;

class PrintDocumentController extends Controller
{
    public function preview(PrintJob $printJob)
    {
        $printJob->load(['branch', 'printer']);

        $salesOrderId = $printJob->payload['sales_order_id'] ?? null;
        if (!$salesOrderId) {
            abort(404, 'No sales order reference in print job.');
        }

        $salesOrder = SalesOrder::with([
            'branch', 'cashier', 'customer',
            'lines.product.category', 'lines.variant',
            'payments.method',
            'restaurantTable.floor',
            'restaurantTableSession.waiter',
        ])->findOrFail($salesOrderId);

        $layout = ReceiptLayoutSetting::where('branch_id', $salesOrder->branch_id)
            ->where('document_type', $printJob->document_type)
            ->where('is_active', true)
            ->first();

        if ($printJob->document_type === 'kot') {
            $lineIds   = $printJob->payload['line_ids'] ?? [];
            $kotLines  = $lineIds
                ? $salesOrder->lines->whereIn('id', $lineIds)->values()
                : $salesOrder->lines;

            return view('tenant.printing.documents.kot', [
                'job'        => $printJob,
                'salesOrder' => $salesOrder,
                'kotLines'   => $kotLines,
                'layout'     => $layout,
            ]);
        }

        return view('tenant.printing.documents.receipt', [
            'job'        => $printJob,
            'salesOrder' => $salesOrder,
            'layout'     => $layout,
        ]);
    }
}
