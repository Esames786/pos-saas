<?php

namespace App\Services\Edge;

use App\Models\Tenant\Branch;
use App\Models\Tenant\EdgeInboundPurchaseReturnIngestion;
use App\Models\Tenant\PurchaseReturn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * OFFLINE EDGE — F3: the Cloud's READ-ONLY warm purchase-return projection for one branch appliance.
 *
 * Exactly what a safe offline return to a supplier needs — not the Purchasing database: the branch's recent goods
 * receipts (the canonical SOURCE of a purchase return) with their supplier, optional linked bill, and lines carrying the
 * received quantity, the official already-returned quantity (posted purchase-return lines sourced from that line — the
 * same arithmetic as PurchaseReturnService::returnableForGrnLine), product / variant identity and the GRN unit cost the
 * canonical authority values the return at; the reason codes; the permission names; and the set of Edge purchase-return
 * events the Cloud has already APPLIED (so the appliance subtracts exactly the local events the Cloud position does not
 * yet include). The watermark is a content fingerprint advertised on every heartbeat.
 */
class EdgePurchaseReturnProjectionService
{
    public const PERMISSIONS = ['store' => 'tenant.purchase-returns.store', 'post' => 'tenant.purchase-returns.post'];

    private function windowStart(): Carbon
    {
        return now()->subDays(max(1, (int) config('edge.purchase_returns.grn_window_days', 90)));
    }

    private function appliedWindowStart(): Carbon
    {
        return now()->subDays(max(1, (int) config('edge.purchase_returns.applied_window_days', 45)));
    }

    private function grnIds(int $branchId): array
    {
        return DB::connection('tenant')->table('goods_receipts')->where('branch_id', $branchId)->where('status', 'posted')
            ->where('receipt_date', '>=', $this->windowStart()->toDateString())->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    public function watermark(int $branchId): array
    {
        $conn = DB::connection('tenant');
        $ids = $this->grnIds($branchId);
        $parts = ['grn:' . implode(',', $ids)];
        if ($ids !== []) {
            $g = $conn->table('goods_receipts')->whereIn('id', $ids)->selectRaw('MAX(updated_at) mu, COUNT(*) c')->first();
            $parts[] = 'g:' . $g->c . ':' . $g->mu;
            $l = $conn->table('goods_receipt_lines')->whereIn('goods_receipt_id', $ids)->selectRaw('COUNT(*) c, COALESCE(MAX(id),0) mi, MAX(updated_at) mu, COALESCE(SUM(quantity_received),0) q, COALESCE(SUM(unit_cost),0) uc')->first();
            $parts[] = 'l:' . $l->c . ':' . $l->mi . ':' . $l->mu . ':' . $l->q . ':' . $l->uc;
            $lineIds = $conn->table('goods_receipt_lines')->whereIn('goods_receipt_id', $ids)->pluck('id')->all();
            $r = $conn->table('purchase_return_lines as prl')->join('purchase_returns as pr', 'pr.id', '=', 'prl.purchase_return_id')
                ->where('pr.status', 'posted')->where('prl.source_line_type', 'goods_receipt_line')->whereIn('prl.source_line_id', $lineIds ?: [0])
                ->selectRaw('COUNT(*) c, COALESCE(MAX(prl.id),0) mi, COALESCE(SUM(prl.quantity),0) q')->first();
            $parts[] = 'r:' . $r->c . ':' . $r->mi . ':' . $r->q;
            $supplierIds = $conn->table('goods_receipts')->whereIn('id', $ids)->pluck('supplier_id')->unique()->all();
            $s = $conn->table('suppliers')->whereIn('id', $supplierIds ?: [0])->selectRaw('COUNT(*) c, MAX(updated_at) mu, SUM(status = \'active\') a')->first();
            $parts[] = 's:' . $s->c . ':' . $s->mu . ':' . $s->a;
        }
        $a = $conn->table('edge_inbound_purchase_return_ingestions')->where('branch_id', $branchId)->where('status', EdgeInboundPurchaseReturnIngestion::STATUS_APPLIED)
            ->selectRaw('COUNT(*) c, COALESCE(MAX(id),0) mi')->first();
        $parts[] = 'app:' . $a->c . ':' . $a->mi;

        return ['watermark' => 'pr:' . substr(hash('sha256', implode('|', $parts) . '|' . $branchId), 0, 40), 'as_of' => now()->toIso8601String()];
    }

    public function package(Branch $branch): array
    {
        $conn = DB::connection('tenant');
        $branchId = (int) $branch->id;
        $wm = $this->watermark($branchId);
        $ids = $this->grnIds($branchId);
        $grns = [];
        if ($ids !== []) {
            $suppliers = $conn->table('suppliers')->whereIn('id', $conn->table('goods_receipts')->whereIn('id', $ids)->pluck('supplier_id')->unique()->all())->get()->keyBy('id');
            $bills = $conn->table('purchase_bills')->whereIn('goods_receipt_id', $ids)->get()->keyBy('goods_receipt_id');
            $lines = $conn->table('goods_receipt_lines as l')->leftJoin('products as p', 'p.id', '=', 'l.product_id')->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
                ->leftJoin('product_variants as v', 'v.id', '=', 'l.product_variant_id')
                ->whereIn('l.goods_receipt_id', $ids)->orderBy('l.id')->get(['l.*', 'p.name as product_name', 'u.code as unit_code', 'v.name as variant_name']);
            $returned = $conn->table('purchase_return_lines as prl')->join('purchase_returns as pr', 'pr.id', '=', 'prl.purchase_return_id')
                ->where('pr.status', 'posted')->where('prl.source_line_type', 'goods_receipt_line')->whereIn('prl.source_line_id', $lines->pluck('id')->all() ?: [0])
                ->groupBy('prl.source_line_id')->selectRaw('prl.source_line_id, SUM(prl.quantity) q')->pluck('q', 'source_line_id');
            foreach ($conn->table('goods_receipts')->whereIn('id', $ids)->orderByDesc('receipt_date')->orderByDesc('id')->get() as $g) {
                $s = $suppliers[(int) $g->supplier_id] ?? null;
                $b = $bills[(int) $g->id] ?? null;
                $grns[] = [
                    'cloud_grn_id' => (int) $g->id, 'grn_no' => (string) $g->grn_no, 'branch_id' => (int) $g->branch_id, 'status' => (string) $g->status,
                    'receipt_date' => $g->receipt_date ? Carbon::parse($g->receipt_date)->toDateString() : null, 'notes' => $g->notes,
                    'cloud_supplier_id' => (int) $g->supplier_id, 'supplier_code' => $s?->code, 'supplier_name' => $s?->name, 'supplier_status' => $s?->status,
                    'cloud_bill_id' => $b ? (int) $b->id : null, 'bill_no' => $b?->bill_no,
                    'updated_at' => $g->updated_at ? Carbon::parse($g->updated_at)->toIso8601String() : null,
                    'lines' => $lines->where('goods_receipt_id', $g->id)->map(fn ($l) => [
                        'cloud_grn_line_id' => (int) $l->id, 'product_id' => (int) $l->product_id, 'product_variant_id' => $l->product_variant_id !== null ? (int) $l->product_variant_id : null,
                        'product_name' => (string) ($l->product_name ?? ''), 'variant_name' => $l->variant_name, 'unit_code' => $l->unit_code,
                        'batch_no' => $l->batch_no, 'expiry_date' => $l->expiry_date ? Carbon::parse($l->expiry_date)->toDateString() : null,
                        'quantity_received' => round((float) $l->quantity_received, 3), 'cloud_returned_quantity' => round((float) ($returned[(int) $l->id] ?? 0), 3),
                        'unit_cost' => round((float) $l->unit_cost, 4),
                    ])->values()->all(),
                ];
            }
        }
        $applied = EdgeInboundPurchaseReturnIngestion::query()->where('branch_id', $branchId)->where('status', EdgeInboundPurchaseReturnIngestion::STATUS_APPLIED)
            ->where('ingested_at', '>=', $this->appliedWindowStart())->orderBy('id')->get(['event_uuid', 'official_return_no', 'ingested_at'])
            ->map(fn ($r) => ['event_uuid' => (string) $r->event_uuid, 'official_return_no' => $r->official_return_no, 'applied_at' => $r->ingested_at?->toIso8601String()])->values()->all();

        return [
            'branch_id' => $branchId, 'watermark' => $wm['watermark'], 'as_of' => $wm['as_of'],
            'grn_window_days' => (int) config('edge.purchase_returns.grn_window_days', 90),
            'reason_codes' => PurchaseReturn::REASON_CODES, 'permissions' => self::PERMISSIONS,
            'rules' => ['source_receipt_required_offline' => true, 'standalone_return_online_only' => true, 'valuation' => 'grn_line_unit_cost', 'gl' => 'Dr 2100 Accounts Payable / Cr 1400 Inventory Asset'],
            'grns' => $grns, 'applied_events' => $applied,
        ];
    }
}
