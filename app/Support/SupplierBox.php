<?php

namespace App\Support;

use App\Models\Tenant\PurchaseOrder;
use Illuminate\Support\Collection;

/**
 * PURCHASING-V2-2 — what the supplier box (purchasing/partials/supplier-box) needs, for the GRN, PO and
 * purchase-return screens: every active supplier with code, phone, what is owed and payment days, and — on a
 * GRN — the orders still waiting to be received, per supplier.
 */
final class SupplierBox
{
    /** @param  Collection<int, \App\Models\Tenant\Supplier>  $suppliers */
    public static function data(Collection $suppliers, bool $withOrders = false): array
    {
        $box = ['suppliers' => $suppliers->map(fn ($s) => [
            'id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'phone' => $s->phone,
            'balance' => round((float) $s->current_balance, 2), 'terms' => (int) $s->payment_terms_days,
        ])->values()->all(), 'orders' => []];

        if ($withOrders) {
            $box['orders'] = PurchaseOrder::whereIn('status', ['approved', 'partially_received'])
                ->orderBy('order_date')->get(['id', 'po_no', 'supplier_id', 'order_date', 'total_amount', 'status'])
                ->groupBy('supplier_id')
                ->map(fn ($rows) => $rows->map(fn ($o) => ['id' => $o->id, 'po_no' => $o->po_no, 'date' => $o->order_date?->format('d M'),
                    'total' => round((float) $o->total_amount, 2), 'part' => $o->status === 'partially_received'])->values())
                ->all();
        }

        return $box;
    }
}
