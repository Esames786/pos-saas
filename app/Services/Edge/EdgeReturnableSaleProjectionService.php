<?php

namespace App\Services\Edge;

use App\Models\Tenant\Branch;
use App\Models\Tenant\SalesOrder;
use Illuminate\Support\Facades\DB;

/**
 * OFFLINE EDGE — F1 (Cloud side): the RETURNABLE-SALE PROJECTION a branch appliance mirrors while the Cloud writes.
 *
 * Read-only, from the Cloud's own truth: the branch's sales still returnable (status paid / partially_returned)
 * within the configured window, with the lines (all kinds — combo components restore stock in proportion), the
 * tenders (default refund method policy), the posted returns (exact remaining discount/tax allocation) and the unit
 * facts (unit-aware return stepper). The WATERMARK is a content hash over everything that can change a returnable
 * balance, advertised on every heartbeat so the appliance can prove its cache is current.
 */
class EdgeReturnableSaleProjectionService
{
    public const RETURNABLE_STATUSES = ['paid', 'partially_returned'];

    private function windowStart(): string
    {
        $days = max(1, (int) config('edge.returns.cache_window_days', 14));

        return now()->subDays($days)->toDateString();
    }

    private function populationIds(int $branchId): array
    {
        return DB::connection('tenant')->table('sales_orders')
            ->where('branch_id', $branchId)
            ->whereIn('status', self::RETURNABLE_STATUSES)
            ->whereRaw('COALESCE(business_date, DATE(sale_date)) >= ?', [$this->windowStart()])
            ->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** @return array{watermark:string, as_of:string} */
    public function watermark(int $branchId): array
    {
        $conn = DB::connection('tenant');
        $ids = $this->populationIds($branchId);
        $parts = [];
        if ($ids !== []) {
            foreach ($conn->table('sales_orders')->whereIn('id', $ids)->orderBy('id')->get(['id', 'status', 'updated_at', 'grand_total']) as $s) {
                $parts[] = 's:' . $s->id . ':' . $s->status . ':' . $s->updated_at . ':' . $s->grand_total;
            }
            foreach ($conn->table('sales_order_lines')->whereIn('sales_order_id', $ids)->orderBy('id')->get(['id', 'quantity', 'returned_quantity']) as $l) {
                $parts[] = 'l:' . $l->id . ':' . $l->quantity . ':' . $l->returned_quantity;
            }
            foreach ($conn->table('sales_returns')->whereIn('sales_order_id', $ids)->orderBy('id')->get(['id', 'status', 'grand_total', 'updated_at']) as $r) {
                $parts[] = 'r:' . $r->id . ':' . $r->status . ':' . $r->grand_total . ':' . $r->updated_at;
            }
        }

        return ['watermark' => 'rw:' . substr(hash('sha256', implode('|', $parts) . '|' . $branchId), 0, 40), 'as_of' => now()->toIso8601String()];
    }

    public function package(Branch $branch): array
    {
        $conn = DB::connection('tenant');
        $branchId = (int) $branch->id;
        $wm = $this->watermark($branchId);
        $ids = $this->populationIds($branchId);
        $sales = [];
        if ($ids !== []) {
            $orders = SalesOrder::on('tenant')->whereIn('id', $ids)->with(['lines.product.unit', 'payments.method', 'returns.lines'])->orderBy('id')->get();
            foreach ($orders as $o) {
                $sales[] = [
                    'cloud_sales_order_id' => (int) $o->id,
                    'sale_uuid' => $o->sale_uuid ?: null,
                    'sale_no' => (string) $o->sale_no,
                    'status' => (string) $o->status,
                    'updated_at' => optional($o->updated_at)->toIso8601String(),
                    'order_type' => (string) $o->order_type,
                    'sale_date' => optional($o->sale_date)->toIso8601String() ?? (string) $o->sale_date,
                    'business_date' => $o->business_date ? \Illuminate\Support\Carbon::parse($o->business_date)->toDateString() : null,
                    'completed_at' => optional($o->completed_at)->toIso8601String(),
                    'terminal_id' => $o->terminal_id ? (int) $o->terminal_id : null,
                    'customer_id' => $o->customer_id ? (int) $o->customer_id : null,
                    'customer_name' => $o->customer_name,
                    'customer_phone' => $o->customer_phone,
                    'restaurant_waiter_id' => $o->restaurant_waiter_id ? (int) $o->restaurant_waiter_id : null,
                    'vehicle_number' => $o->vehicle_number,
                    'created_by_user_id' => $o->created_by_user_id ? (int) $o->created_by_user_id : null,
                    'subtotal' => (float) $o->subtotal, 'discount_type' => (string) ($o->discount_type ?? 'none'), 'discount_value' => (float) ($o->discount_value ?? 0),
                    'discount_amount' => (float) $o->discount_amount, 'promo_code' => $o->promo_code, 'tax_amount' => (float) $o->tax_amount,
                    'service_charge_amount' => (float) ($o->service_charge_amount ?? 0), 'delivery_charge_amount' => (float) ($o->delivery_charge_amount ?? 0),
                    'tip_amount' => (float) ($o->tip_amount ?? 0), 'grand_total' => (float) $o->grand_total, 'paid_amount' => (float) ($o->paid_amount ?? $o->grand_total),
                    'change_amount' => (float) ($o->change_amount ?? 0),
                    'lines' => $o->lines->map(fn ($l) => [
                        'cloud_line_id' => (int) $l->id,
                        'line_uuid' => $l->line_uuid ?: null,
                        'parent_cloud_line_id' => $l->parent_sales_order_line_id ? (int) $l->parent_sales_order_line_id : null,
                        'line_kind' => (string) ($l->line_kind ?? 'standard'),
                        'combo_id' => $l->combo_id ? (int) $l->combo_id : null,
                        'product_id' => (int) $l->product_id,
                        'product_variant_id' => $l->product_variant_id ? (int) $l->product_variant_id : null,
                        'product_name' => (string) $l->product_name,
                        'quantity' => (float) $l->quantity,
                        'returned_quantity' => (float) $l->returned_quantity,
                        'unit_code' => $l->unit_code ?: ($l->product?->unit?->code),
                        'unit_type' => $l->product?->unit?->unit_type ?? 'quantity',
                        'unit_price' => (float) $l->unit_price,
                        'unit_cost' => (float) ($l->unit_cost ?? 0),
                        'discount_amount' => (float) ($l->discount_amount ?? 0),
                        'tax_amount' => (float) ($l->tax_amount ?? 0),
                        'line_total' => (float) $l->line_total,
                    ])->values()->all(),
                    'payments' => $o->payments->map(fn ($p) => [
                        'payment_method_id' => (int) $p->payment_method_id, 'method_type' => (string) ($p->method?->method_type ?? ''),
                        'amount' => (float) $p->amount, 'tendered_amount' => $p->tendered_amount !== null ? (float) $p->tendered_amount : null,
                        'change_amount' => (float) ($p->change_amount ?? 0), 'transaction_ref' => $p->transaction_ref,
                    ])->values()->all(),
                    'returns' => $o->returns->where('status', 'posted')->map(fn ($r) => [
                        'cloud_return_id' => (int) $r->id, 'return_no' => (string) $r->return_no,
                        'return_date' => optional($r->return_date)->toIso8601String(), 'business_date' => $r->business_date ? \Illuminate\Support\Carbon::parse($r->business_date)->toDateString() : null,
                        'subtotal' => (float) $r->subtotal, 'discount_amount' => (float) ($r->discount_amount ?? 0), 'tax_amount' => (float) $r->tax_amount,
                        'delivery_charge_amount' => (float) ($r->delivery_charge_amount ?? 0), 'grand_total' => (float) $r->grand_total,
                        'refund_method' => $r->refund_method, 'refund_amount' => (float) $r->refund_amount,
                        'lines' => $r->lines->map(fn ($rl) => [
                            'cloud_line_id' => (int) $rl->sales_order_line_id, 'product_id' => (int) $rl->product_id, 'product_variant_id' => $rl->product_variant_id ? (int) $rl->product_variant_id : null,
                            'quantity' => (float) $rl->quantity, 'unit_price' => (float) $rl->unit_price, 'discount_amount' => (float) ($rl->discount_amount ?? 0),
                            'tax_amount' => (float) $rl->tax_amount, 'line_total' => (float) $rl->line_total,
                        ])->values()->all(),
                    ])->values()->all(),
                ];
            }
        }

        return [
            'branch_id' => $branchId,
            'watermark' => $wm['watermark'],
            'as_of' => $wm['as_of'],
            'window_days' => (int) config('edge.returns.cache_window_days', 14),
            'sales' => $sales,
        ];
    }
}
