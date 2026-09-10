<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeSyncOutbox;
use App\Models\Tenant\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * OFFLINE EDGE — F1: the RETURNABLE-SALE WARM CACHE (appliance side).
 *
 * A read-only projection of the branch's returnable Cloud sales, held as SHADOW rows in the canonical sales tables
 * (sales_orders.status = 'cloud_mirror', never a report population status, never synced, never sold from) plus a
 * mapping to the Cloud identities and the Cloud's authoritative returned quantities. Cloud returns already posted
 * against those sales are mirrored as sales_returns.status = 'cloud_mirror' so the canonical allocation of remaining
 * discount/tax is exact. It is a RETURN VALIDATION CACHE — the Cloud stays the official financial truth.
 *
 * RETURNABLE (per shadow line) = sold − Cloud returned − LOCAL returns the Cloud has not yet reflected. The shadow's
 * `returned_quantity` is maintained as exactly that sum, so the canonical formula (quantity − returned_quantity) is
 * the returnable balance and two terminals serialize on the same locked rows.
 */
class EdgeReturnableSaleCacheService
{
    public const SHADOW_STATUS = 'cloud_mirror';

    public function __construct(private readonly EdgeBranchContext $context)
    {
    }

    /** Apply a Cloud package atomically. Returns counts. */
    public function apply(array $package): array
    {
        $meta = $this->context->requireCurrent();
        $branchId = (int) $meta->branch_id;
        if ((int) ($package['branch_id'] ?? 0) !== $branchId) {
            throw new RuntimeException('RETURNABLE_CACHE_WRONG_BRANCH: the package is not for this branch.');
        }
        $asOf = isset($package['as_of']) ? Carbon::parse($package['as_of']) : now();
        $stats = ['sales' => 0, 'skipped_local' => 0, 'mirrored_returns' => 0];

        DB::connection('tenant')->transaction(function () use ($package, $branchId, $asOf, &$stats, $meta) {
            $conn = DB::connection('tenant');
            foreach (($package['sales'] ?? []) as $sale) {
                $saleUuid = $sale['sale_uuid'] ?? null;
                // An Edge-originated sale that still exists locally IS the local truth — never shadow it.
                if ($saleUuid && SalesOrder::on('tenant')->where('sale_uuid', $saleUuid)->where('status', '!=', self::SHADOW_STATUS)->exists()) {
                    $stats['skipped_local']++;
                    continue;
                }
                $map = $conn->table('edge_returnable_sales')->where('cloud_sales_order_id', (int) $sale['cloud_sales_order_id'])->lockForUpdate()->first();
                $localSaleId = $map ? (int) $map->local_sales_order_id : $this->insertShadowSale($sale, $branchId);
                if ($map) {
                    $conn->table('sales_orders')->where('id', $localSaleId)->update([
                        'grand_total' => (float) $sale['grand_total'], 'delivery_charge_amount' => (float) ($sale['delivery_charge_amount'] ?? 0),
                        'discount_amount' => (float) ($sale['discount_amount'] ?? 0), 'business_date' => $sale['business_date'] ?? null, 'updated_at' => now(),
                    ]);
                    $conn->table('edge_returnable_sales')->where('id', $map->id)->update([
                        'cloud_status' => (string) $sale['status'], 'cloud_updated_at' => $sale['updated_at'] ?? null, 'refreshed_at' => now(), 'updated_at' => now(),
                    ]);
                } else {
                    $conn->table('edge_returnable_sales')->insert([
                        'cloud_sales_order_id' => (int) $sale['cloud_sales_order_id'], 'local_sales_order_id' => $localSaleId, 'sale_uuid' => $saleUuid,
                        'sale_no' => (string) $sale['sale_no'], 'cloud_status' => (string) $sale['status'], 'cloud_updated_at' => $sale['updated_at'] ?? null,
                        'refreshed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $map = $conn->table('edge_returnable_sales')->where('local_sales_order_id', $localSaleId)->first();
                }
                $lineMap = $this->upsertShadowLines($map, $localSaleId, $sale['lines'] ?? []);
                $stats['mirrored_returns'] += $this->mirrorCloudReturns($localSaleId, $lineMap, $sale['returns'] ?? []);
                $this->upsertShadowPayments($localSaleId, $sale['payments'] ?? []);
                $this->recomputeReturnedQuantities($localSaleId, $lineMap, $asOf);
                $stats['sales']++;
            }
            $meta->forceFill([
                'returnable_cache_watermark' => (string) ($package['watermark'] ?? ''),
                'returnable_cache_as_of' => $asOf,
                'returnable_cache_refreshed_at' => now(),
            ])->save();
        });

        return $stats;
    }

    /** Is this local sales_orders row a shadow of a Cloud sale? */
    public function isShadow(SalesOrder $sale): bool
    {
        return (string) $sale->status === self::SHADOW_STATUS;
    }

    /** Mapping row for a shadow sale (cloud ids), or null for a local sale. */
    public function mappingFor(int $localSaleId): ?object
    {
        return DB::connection('tenant')->table('edge_returnable_sales')->where('local_sales_order_id', $localSaleId)->first();
    }

    /** @return array<int,int> local line id => cloud line id */
    public function cloudLineIds(int $localSaleId): array
    {
        $map = $this->mappingFor($localSaleId);
        if (! $map) {
            return [];
        }

        return DB::connection('tenant')->table('edge_returnable_sale_lines')->where('returnable_sale_id', $map->id)
            ->pluck('cloud_sales_order_line_id', 'local_sales_order_line_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * The freshness PROOF for returning a Cloud sale offline: the cache equals the watermark the Cloud last advertised,
     * or was refreshed strictly after the last acknowledged heartbeat.
     *
     * @return array{ok:bool, reasons:array<int,string>, watermark:?string, as_of:?string}
     */
    public function freshness(): array
    {
        $meta = $this->context->current();
        if (! $meta) {
            return ['ok' => false, 'reasons' => ['appliance not bound'], 'watermark' => null, 'as_of' => null];
        }
        $seen = $meta->standby_returnable_watermark_seen !== null ? (string) $meta->standby_returnable_watermark_seen : null;
        $cached = $meta->returnable_cache_watermark !== null ? (string) $meta->returnable_cache_watermark : null;
        $asOf = $meta->returnable_cache_as_of ? Carbon::parse($meta->returnable_cache_as_of) : null;
        $lastAck = $meta->authority_last_ack_at ? Carbon::parse($meta->authority_last_ack_at) : null;
        $reasons = [];
        $ok = false;
        if ($cached === null) {
            $reasons[] = 'no returnable-sale information has been received from the Cloud';
        } elseif ($seen === null) {
            $reasons[] = 'the Cloud never advertised a returnable-sale watermark';
        } elseif ($cached === $seen) {
            $ok = true;
        } elseif ($asOf !== null && $lastAck !== null && $asOf->greaterThan($lastAck)) {
            $ok = true;
        } else {
            $reasons[] = 'the returnable-sale information does not equal the last advertised Cloud position';
        }

        return ['ok' => $ok, 'reasons' => $reasons, 'watermark' => $cached, 'as_of' => $asOf?->toIso8601String()];
    }

    private function insertShadowSale(array $sale, int $branchId): int
    {
        $conn = DB::connection('tenant');
        $terminalId = isset($sale['terminal_id']) && $conn->table('terminals')->where('id', (int) $sale['terminal_id'])->exists() ? (int) $sale['terminal_id'] : null;
        $userId = isset($sale['created_by_user_id']) && $conn->table('users')->where('id', (int) $sale['created_by_user_id'])->exists() ? (int) $sale['created_by_user_id'] : null;
        $customerId = isset($sale['customer_id']) && $conn->table('customers')->where('id', (int) $sale['customer_id'])->exists() ? (int) $sale['customer_id'] : null;
        $waiterId = isset($sale['restaurant_waiter_id']) && $conn->table('restaurant_waiters')->where('id', (int) $sale['restaurant_waiter_id'])->exists() ? (int) $sale['restaurant_waiter_id'] : null;

        $row = new SalesOrder([
            'sale_no' => (string) $sale['sale_no'],
            'client_uuid' => null,
            'branch_id' => $branchId,
            'terminal_id' => $terminalId,
            'customer_id' => $customerId,
            'customer_name' => $sale['customer_name'] ?? null,
            'customer_phone' => $sale['customer_phone'] ?? null,
            'restaurant_waiter_id' => $waiterId,
            'vehicle_number' => $sale['vehicle_number'] ?? null,
            'order_source' => 'pos',
            'order_type' => (string) ($sale['order_type'] ?? 'takeaway'),
            'sale_date' => $sale['sale_date'] ?? now(),
            'business_date' => $sale['business_date'] ?? null,
            'subtotal' => (float) ($sale['subtotal'] ?? 0),
            'discount_type' => in_array((string) ($sale['discount_type'] ?? 'none'), ['none', 'fixed', 'percent'], true) ? (string) ($sale['discount_type'] ?? 'none') : 'none',
            'discount_value' => (float) ($sale['discount_value'] ?? 0),
            'discount_amount' => (float) ($sale['discount_amount'] ?? 0),
            'promo_code' => $sale['promo_code'] ?? null,
            'tax_amount' => (float) ($sale['tax_amount'] ?? 0),
            'service_charge_amount' => (float) ($sale['service_charge_amount'] ?? 0),
            'delivery_charge_amount' => (float) ($sale['delivery_charge_amount'] ?? 0),
            'tip_amount' => (float) ($sale['tip_amount'] ?? 0),
            'grand_total' => (float) ($sale['grand_total'] ?? 0),
            'paid_amount' => (float) ($sale['paid_amount'] ?? $sale['grand_total'] ?? 0),
            'change_amount' => (float) ($sale['change_amount'] ?? 0),
            'status' => self::SHADOW_STATUS,
            'inventory_posted' => true,
            'completed_at' => $sale['completed_at'] ?? ($sale['sale_date'] ?? now()),
            'created_by_user_id' => $userId,
        ]);
        if (! empty($sale['sale_uuid'])) {
            $row->sale_uuid = (string) $sale['sale_uuid'];
        }
        $row->save();

        return (int) $row->id;
    }

    /** @return array<int,int> cloud line id => local line id */
    private function upsertShadowLines(object $map, int $localSaleId, array $lines): array
    {
        $conn = DB::connection('tenant');
        $existing = $conn->table('edge_returnable_sale_lines')->where('returnable_sale_id', $map->id)->pluck('local_sales_order_line_id', 'cloud_sales_order_line_id')->map(fn ($v) => (int) $v)->all();
        $lineMap = $existing;
        // Two passes: parents first so component rows can reference their local parent.
        $ordered = collect($lines)->sortBy(fn ($l) => empty($l['parent_cloud_line_id']) ? 0 : 1)->values()->all();
        foreach ($ordered as $l) {
            $cloudLineId = (int) $l['cloud_line_id'];
            $parentLocal = ! empty($l['parent_cloud_line_id']) ? ($lineMap[(int) $l['parent_cloud_line_id']] ?? null) : null;
            $unitCode = $l['unit_code'] ?? null;
            if (isset($lineMap[$cloudLineId])) {
                $conn->table('sales_order_lines')->where('id', $lineMap[$cloudLineId])->update([
                    'quantity' => (float) $l['quantity'], 'unit_price' => (float) $l['unit_price'], 'discount_amount' => (float) ($l['discount_amount'] ?? 0),
                    'tax_amount' => (float) ($l['tax_amount'] ?? 0), 'line_total' => (float) $l['line_total'], 'unit_cost' => (float) ($l['unit_cost'] ?? 0),
                    'parent_sales_order_line_id' => $parentLocal, 'updated_at' => now(),
                ]);
                $conn->table('edge_returnable_sale_lines')->where('cloud_sales_order_line_id', $cloudLineId)->update([
                    'sold_quantity' => (float) $l['quantity'], 'cloud_returned_quantity' => (float) ($l['returned_quantity'] ?? 0),
                    'unit_code' => $unitCode, 'unit_type' => $l['unit_type'] ?? null, 'updated_at' => now(),
                ]);
                continue;
            }
            $localLineId = $conn->table('sales_order_lines')->insertGetId([
                'sales_order_id' => $localSaleId,
                'line_kind' => (string) ($l['line_kind'] ?? 'standard'),
                'combo_id' => $l['combo_id'] ?? null,
                'parent_sales_order_line_id' => $parentLocal,
                'product_id' => (int) $l['product_id'],
                'product_variant_id' => $l['product_variant_id'] ?? null,
                'product_name' => (string) ($l['product_name'] ?? ''),
                'unit_code' => $unitCode,
                'quantity' => (float) $l['quantity'],
                'returned_quantity' => (float) ($l['returned_quantity'] ?? 0),
                'unit_price' => (float) $l['unit_price'],
                'unit_cost' => (float) ($l['unit_cost'] ?? 0),
                'cost_total' => 0,
                'discount_amount' => (float) ($l['discount_amount'] ?? 0),
                'tax_amount' => (float) ($l['tax_amount'] ?? 0),
                'line_total' => (float) $l['line_total'],
                'line_uuid' => $l['line_uuid'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $conn->table('edge_returnable_sale_lines')->insert([
                'returnable_sale_id' => $map->id, 'cloud_sales_order_line_id' => $cloudLineId, 'local_sales_order_line_id' => $localLineId,
                'sold_quantity' => (float) $l['quantity'], 'cloud_returned_quantity' => (float) ($l['returned_quantity'] ?? 0),
                'unit_code' => $unitCode, 'unit_type' => $l['unit_type'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $lineMap[$cloudLineId] = $localLineId;
        }

        return $lineMap;
    }

    /** Mirror the Cloud's posted returns (status cloud_mirror) so remaining discount/tax allocation is exact. */
    private function mirrorCloudReturns(int $localSaleId, array $lineMap, array $returns): int
    {
        $conn = DB::connection('tenant');
        $n = 0;
        foreach ($returns as $r) {
            $cloudReturnId = (int) $r['cloud_return_id'];
            if ($conn->table('sales_returns')->where('edge_cloud_sales_return_id', $cloudReturnId)->exists()) {
                continue;
            }
            $returnId = $conn->table('sales_returns')->insertGetId([
                'return_no' => 'CLOUD-' . (string) $r['return_no'],
                'sales_order_id' => $localSaleId,
                'branch_id' => (int) $this->context->requireCurrent()->branch_id,
                'return_date' => $r['return_date'] ?? now(),
                'business_date' => $r['business_date'] ?? null,
                'subtotal' => (float) ($r['subtotal'] ?? 0), 'discount_amount' => (float) ($r['discount_amount'] ?? 0), 'tax_amount' => (float) ($r['tax_amount'] ?? 0),
                'delivery_charge_amount' => (float) ($r['delivery_charge_amount'] ?? 0), 'grand_total' => (float) ($r['grand_total'] ?? 0),
                'refund_method' => $r['refund_method'] ?? null, 'refund_amount' => (float) ($r['refund_amount'] ?? 0),
                'status' => self::SHADOW_STATUS, 'edge_origin' => 'cloud_mirror', 'edge_cloud_sales_return_id' => $cloudReturnId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (($r['lines'] ?? []) as $rl) {
                $localLine = $lineMap[(int) $rl['cloud_line_id']] ?? null;
                if ($localLine === null) {
                    continue;
                }
                $conn->table('sales_return_lines')->insert([
                    'sales_return_id' => $returnId, 'sales_order_line_id' => $localLine, 'product_id' => (int) $rl['product_id'],
                    'product_variant_id' => $rl['product_variant_id'] ?? null, 'quantity' => (float) $rl['quantity'], 'unit_price' => (float) ($rl['unit_price'] ?? 0),
                    'discount_amount' => (float) ($rl['discount_amount'] ?? 0), 'tax_amount' => (float) ($rl['tax_amount'] ?? 0), 'line_total' => (float) ($rl['line_total'] ?? 0),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $n++;
        }

        return $n;
    }

    private function upsertShadowPayments(int $localSaleId, array $payments): void
    {
        $conn = DB::connection('tenant');
        if ($conn->table('sale_payments')->where('sales_order_id', $localSaleId)->exists()) {
            return;
        }
        foreach ($payments as $p) {
            if (! $conn->table('payment_methods')->where('id', (int) $p['payment_method_id'])->exists()) {
                continue;
            }
            $row = new \App\Models\Tenant\SalePayment([
                'sales_order_id' => $localSaleId, 'payment_method_id' => (int) $p['payment_method_id'], 'amount' => (float) $p['amount'],
                'tendered_amount' => $p['tendered_amount'] ?? null, 'change_amount' => (float) ($p['change_amount'] ?? 0), 'transaction_ref' => $p['transaction_ref'] ?? null,
            ]);
            $row->payment_uuid = (string) Str::ulid();
            $row->save();
        }
    }

    /**
     * shadow.returned_quantity = Cloud returned + LOCAL returns of this line the Cloud has not yet reflected
     * (their return event is not acknowledged, or was acknowledged after the package was taken).
     */
    private function recomputeReturnedQuantities(int $localSaleId, array $lineMap, Carbon $asOf): void
    {
        $conn = DB::connection('tenant');
        foreach ($lineMap as $cloudLineId => $localLineId) {
            $cloudReturned = (float) $conn->table('edge_returnable_sale_lines')->where('cloud_sales_order_line_id', $cloudLineId)->value('cloud_returned_quantity');
            $localPending = (float) $conn->table('sales_return_lines as rl')
                ->join('sales_returns as r', 'r.id', '=', 'rl.sales_return_id')
                ->leftJoin('edge_sync_outbox as o', 'o.sale_uuid', '=', 'r.edge_return_uuid')
                ->where('rl.sales_order_line_id', $localLineId)
                ->where('r.status', 'posted')->where('r.edge_origin', 'local')
                ->where(function ($q) use ($asOf) {
                    $q->whereNull('o.acknowledged_at')->orWhere('o.acknowledged_at', '>', $asOf)
                        ->orWhere('o.state', '!=', EdgeSyncOutbox::STATE_ACKNOWLEDGED);
                })
                ->sum('rl.quantity');
            $conn->table('sales_order_lines')->where('id', $localLineId)->update(['returned_quantity' => $cloudReturned + $localPending, 'updated_at' => now()]);
        }
    }
}
