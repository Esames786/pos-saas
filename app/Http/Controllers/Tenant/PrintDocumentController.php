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

        $salesOrderId = $printJob->reference_id ?: ($printJob->payload['sales_order_id'] ?? null);
        if (!$salesOrderId) {
            abort(404, 'No sales order reference in print job.');
        }

        $salesOrder = SalesOrder::with([
            'branch', 'createdBy', 'customer',
            'lines.product.category', 'lines.variant',
            'payments.method',
            'restaurantTable.floor',
            'restaurantTableSession.waiter',
            'restaurantWaiter',
        ])->findOrFail($salesOrderId);

        $layout = ReceiptLayoutSetting::where('branch_id', $salesOrder->branch_id)
            ->where('document_type', $printJob->document_type)
            ->where('is_active', true)
            ->first();

        if ($printJob->document_type === 'kot') {
            $lineIds        = $printJob->payload['line_ids'] ?? [];
            $lineQuantities = collect($printJob->payload['line_quantities'] ?? []);
            $isReprint      = $printJob->payload['is_reprint'] ?? false;

            $kotLines = $lineIds
                ? $salesOrder->lines->whereIn('id', $lineIds)->values()
                : $salesOrder->lines;

            if (!$isReprint) {
                if ($lineQuantities->isNotEmpty()) {
                    // Use payload quantities as source of truth.
                    // The model's kot_sent_quantity may already be stamped (network jobs),
                    // so recalculating delta from model would show empty lines.
                    $kotLines = $kotLines->filter(
                        fn ($l) => (float) $lineQuantities->get((string) $l->id, 0) > 0
                    )->values();
                } else {
                    // Backward compat: jobs created before line_quantities was added to payload.
                    $kotLines = $kotLines->filter(
                        fn ($l) => ((float) $l->quantity - (float) ($l->kot_sent_quantity ?? 0)) > 0
                    )->values();
                }
            }

            return view('tenant.printing.documents.kot', [
                'job'            => $printJob,
                'salesOrder'     => $salesOrder,
                'kotLines'       => $kotLines,
                'layout'         => $layout,
                'isReprint'      => $isReprint,
                'lineQuantities' => $lineQuantities,
            ]);
        }

        return view('tenant.printing.documents.receipt', [
            'job'        => $printJob,
            'salesOrder' => $salesOrder,
            'layout'     => $layout,
        ]);
    }
}
