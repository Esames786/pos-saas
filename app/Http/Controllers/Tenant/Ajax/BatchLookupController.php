<?php

namespace App\Http\Controllers\Tenant\Ajax;

use App\Http\Controllers\Controller;
use App\Models\Tenant\GoodsReceiptLine;
use App\Models\Tenant\InventoryBatch;
use App\Models\Tenant\StockBalance;
use App\Support\ScreenAccess;
use App\Support\TenantClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * PURCHASING-V2-2 — the batch box: one item's batches in one branch, nearest expiry first, with the stock
 * left in each (stock is kept per batch), what one unit of it cost, and its expiry as MM/YY. Expired batches
 * are left out unless asked for (a return wants them; a GRN never receives into one).
 *
 * PURCHASE-RETURN-V2: without an item but with a supplier and `before=YYYY-MM`, it answers the expiry sweep
 * instead: every batch in the branch that came from this supplier, still has stock, and has expired (or will)
 * by the end of that month.
 */
class BatchLookupController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        ScreenAccess::purchasing(['tenant.goods-receipts.create', 'tenant.purchase-orders.create', 'tenant.purchase-returns.create', 'tenant.inventory.batches']);

        $productId = (int) $request->input('product_id');
        $branchId = (int) $request->input('branch_id');
        $supplierId = (int) $request->input('supplier_id');
        if ($branchId <= 0) {
            return response()->json(['batches' => []]);
        }
        if ($productId <= 0) {
            return $supplierId > 0 && preg_match('/^\d{4}-\d{2}$/', (string) $request->input('before'))
                ? $this->sweep($branchId, $supplierId, (string) $request->input('before'))
                : response()->json(['batches' => []]);
        }

        $today = app(TenantClock::class)->now()->startOfDay();
        $withExpired = $request->boolean('expired');
        $stocked = $request->boolean('stocked');

        $batches = InventoryBatch::query()
            ->where('branch_id', $branchId)->where('product_id', $productId)
            ->where('status', '!=', 'closed')
            ->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->orderByDesc('id')
            ->limit(100)
            ->get();
        $stock = self::stockOf($batches->pluck('id')->all());

        $rows = $batches
            ->map(fn (InventoryBatch $b) => self::row($b, $stock[$b->id] ?? null, $today))
            ->filter(fn ($b) => ($withExpired || ! $b['expired']) && (! $stocked || $b['stock'] > 0))
            ->values();

        return response()->json(['batches' => $rows]);
    }

    private function sweep(int $branchId, int $supplierId, string $before): JsonResponse
    {
        $today = app(TenantClock::class)->now()->startOfDay();
        $end = Carbon::createFromFormat('Y-m-d', $before . '-01')->endOfMonth()->toDateString();

        // Which item + batch came in from this supplier at this branch.
        $pairs = GoodsReceiptLine::query()
            ->join('goods_receipts as g', 'g.id', '=', 'goods_receipt_lines.goods_receipt_id')
            ->where('g.supplier_id', $supplierId)->where('g.branch_id', $branchId)->where('g.status', 'posted')
            ->whereNotNull('goods_receipt_lines.batch_no')
            ->distinct()->get(['goods_receipt_lines.product_id', 'goods_receipt_lines.batch_no']);
        if ($pairs->isEmpty()) {
            return response()->json(['batches' => []]);
        }

        $batches = InventoryBatch::query()
            ->with('product:id,name')
            ->where('branch_id', $branchId)
            ->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', $end)
            ->where(function ($w) use ($pairs) {
                foreach ($pairs as $p) {
                    $w->orWhere(fn ($x) => $x->where('product_id', $p->product_id)->where('batch_no', $p->batch_no));
                }
            })
            ->orderBy('expiry_date')
            ->limit(200)
            ->get();
        $stock = self::stockOf($batches->pluck('id')->all());

        $rows = $batches
            ->map(fn (InventoryBatch $b) => self::row($b, $stock[$b->id] ?? null, $today) + ['product_id' => $b->product_id, 'product' => $b->product?->name])
            ->filter(fn ($b) => $b['stock'] > 0)
            ->values();

        return response()->json(['batches' => $rows]);
    }

    /** @return array<int, object{qty: float, cost: float}> per batch: stock left and the cost of one unit of it */
    private static function stockOf(array $batchIds): array
    {
        if (! $batchIds) {
            return [];
        }

        return StockBalance::query()
            ->whereIn('inventory_batch_id', $batchIds)
            ->groupBy('inventory_batch_id')
            ->selectRaw('inventory_batch_id, SUM(quantity_on_hand) as qty, SUM(CASE WHEN quantity_on_hand > 0 THEN quantity_on_hand * average_cost ELSE 0 END) as val, SUM(CASE WHEN quantity_on_hand > 0 THEN quantity_on_hand ELSE 0 END) as pos')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->inventory_batch_id => (object) ['qty' => (float) $r->qty, 'cost' => (float) $r->pos > 0 ? (float) $r->val / (float) $r->pos : 0.0]])
            ->all();
    }

    // TenantClock::now() is a Carbon\Carbon, not the Illuminate\Support one — accept any Carbon date.
    private static function row(InventoryBatch $b, ?object $s, \Carbon\CarbonInterface $today): array
    {
        $exp = $b->expiry_date;

        return [
            'id'          => $b->id,
            'batch_no'    => $b->batch_no,
            'expiry'      => $exp?->format('m/y'),
            'expired'     => $exp !== null && $exp->lt($today),
            'months_left' => $exp ? (int) (($exp->year - $today->year) * 12 + $exp->month - $today->month) : null,
            'stock'       => round($s->qty ?? 0, 3),
            'unit_cost'   => round(($s && $s->cost > 0) ? $s->cost : (float) $b->unit_cost, 4),
        ];
    }
}
