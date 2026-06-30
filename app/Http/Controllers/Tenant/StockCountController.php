<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockAdjustmentLine;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockCountLine;
use App\Models\Tenant\StockCountSession;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockCountController extends Controller
{
    public function index()
    {
        $sessions = StockCountSession::with(['branch'])
            ->withCount('lines')
            ->latest()
            ->paginate(20);

        return view('tenant.stock-counts.index', compact('sessions'));
    }

    public function create()
    {
        $branches = Branch::where('status', 'active')->orderBy('name')->get();

        return view('tenant.stock-counts.create', compact('branches'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'notes'     => ['nullable', 'string', 'max:2000'],
        ]);

        $session = StockCountSession::create([
            'count_no'            => $this->nextCountNo(),
            'branch_id'           => (int) $data['branch_id'],
            'status'              => 'counting',
            'started_by_user_id'  => auth('tenant')->id(),
            'started_at'          => now(),
            'notes'               => $data['notes'] ?? null,
        ]);

        return redirect(url('/stock-counts/' . $session->id))
            ->with('success', 'Stock count started.');
    }

    public function show(StockCountSession $stockCountSession)
    {
        $stockCountSession->load([
            'branch',
            'increaseAdjustment',
            'decreaseAdjustment',
            'lines.product.unit',
            'lines.variant',
            'lines.unit',
        ]);

        $products = Product::with(['unit', 'variants'])
            ->where('status', 'active')
            ->where('is_stock_tracked', true)
            ->orderBy('name')
            ->get();

        return view('tenant.stock-counts.show', [
            'session'  => $stockCountSession,
            'products' => $products,
        ]);
    }

    public function addLine(Request $request, StockCountSession $stockCountSession)
    {
        abort_if($stockCountSession->isLocked(), 422, 'This stock count is locked.');

        $data = $request->validate([
            'product_id'         => ['required', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'notes'              => ['nullable', 'string', 'max:2000'],
        ]);

        $product = Product::with(['unit', 'variants'])
            ->where('status', 'active')
            ->findOrFail((int) $data['product_id']);

        $variantId = $data['product_variant_id'] ? (int) $data['product_variant_id'] : null;

        // Resolve to default variant when none specified
        if (!$variantId) {
            $variantId = ProductVariant::where('product_id', $product->id)
                ->where('is_default', true)
                ->where('is_active', true)
                ->value('id');

            if (!$variantId) {
                $variantId = ProductVariant::where('product_id', $product->id)
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->value('id');
            }
        }

        if ($variantId) {
            ProductVariant::where('id', $variantId)
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->firstOrFail();
        }

        $snapshot = $this->stockSnapshot(
            branchId:  (int) $stockCountSession->branch_id,
            productId: (int) $product->id,
            variantId: $variantId,
        );

        $line = StockCountLine::firstOrCreate(
            [
                'stock_count_session_id' => $stockCountSession->id,
                'product_id'             => $product->id,
                'product_variant_id'     => $variantId,
            ],
            [
                'unit_id'          => $product->unit_id,
                'system_quantity'  => $snapshot['quantity'],
                'counted_quantity' => null,
                'variance_quantity'=> 0,
                'average_cost'     => $snapshot['average_cost'],
                'variance_value'   => 0,
                'notes'            => $data['notes'] ?? null,
            ]
        );

        return redirect(url('/stock-counts/' . $stockCountSession->id))
            ->with('success', 'Product added to stock count.')
            ->with('focus_line_id', $line->id);
    }

    public function updateLine(Request $request, StockCountSession $stockCountSession, StockCountLine $line)
    {
        abort_if($stockCountSession->isLocked(), 422, 'This stock count is locked.');
        abort_unless((int) $line->stock_count_session_id === (int) $stockCountSession->id, 404);

        $data = $request->validate([
            'counted_quantity' => ['nullable', 'numeric', 'min:0'],
            'notes'            => ['nullable', 'string', 'max:2000'],
        ]);

        $raw = $data['counted_quantity'];
        $line->counted_quantity = ($raw === null || $raw === '') ? null : round((float) $raw, 3);
        $line->notes            = $data['notes'] ?? null;
        $line->recalculate();
        $line->save();

        return redirect(url('/stock-counts/' . $stockCountSession->id))
            ->with('success', 'Count updated.')
            ->with('focus_line_id', $line->id);
    }

    public function destroyLine(StockCountSession $stockCountSession, StockCountLine $line)
    {
        abort_if($stockCountSession->isLocked(), 422, 'This stock count is locked.');
        abort_unless((int) $line->stock_count_session_id === (int) $stockCountSession->id, 404);

        $line->delete();

        return redirect(url('/stock-counts/' . $stockCountSession->id))
            ->with('success', 'Line removed.');
    }

    public function cancel(StockCountSession $stockCountSession)
    {
        abort_if($stockCountSession->isLocked(), 422, 'This stock count is already locked.');

        $stockCountSession->update([
            'status'               => 'cancelled',
            'cancelled_by_user_id' => auth('tenant')->id(),
            'cancelled_at'         => now(),
        ]);

        return redirect(url('/stock-counts'))
            ->with('success', 'Stock count cancelled.');
    }

    public function post(StockCountSession $stockCountSession, InventoryService $inventoryService)
    {
        try {
            $result = DB::transaction(function () use ($stockCountSession, $inventoryService) {
                $session = StockCountSession::query()
                    ->whereKey($stockCountSession->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($session->isLocked()) {
                    throw new RuntimeException('This stock count is already locked.');
                }

                $session->load(['branch', 'lines.product', 'lines.variant']);

                if ($session->lines->isEmpty()) {
                    throw new RuntimeException('Add at least one product before posting stock count.');
                }

                $incompleteLine = $session->lines->first(
                    fn (StockCountLine $line) => $line->counted_quantity === null
                );

                if ($incompleteLine) {
                    throw new RuntimeException('Complete counted quantity for all lines before posting.');
                }

                foreach ($session->lines as $line) {
                    $line->recalculate();
                    $line->save();
                }

                $session->load(['branch', 'lines.product', 'lines.variant']);

                $positiveLines = $session->lines->filter(
                    fn (StockCountLine $line) => (float) $line->variance_quantity > 0.0004
                );

                $negativeLines = $session->lines->filter(
                    fn (StockCountLine $line) => (float) $line->variance_quantity < -0.0004
                );

                $increaseAdjustment = $positiveLines->isNotEmpty()
                    ? $this->createStockCountAdjustment(
                        session: $session,
                        adjustmentType: 'increase',
                        movementType: 'adjustment_in',
                        lines: $positiveLines,
                        inventoryService: $inventoryService,
                    )
                    : null;

                $decreaseAdjustment = $negativeLines->isNotEmpty()
                    ? $this->createStockCountAdjustment(
                        session: $session,
                        adjustmentType: 'decrease',
                        movementType: 'adjustment_out',
                        lines: $negativeLines,
                        inventoryService: $inventoryService,
                    )
                    : null;

                $session->update([
                    'status'                       => 'posted',
                    'posted_by_user_id'            => auth('tenant')->id(),
                    'posted_at'                    => now(),
                    'increase_stock_adjustment_id' => $increaseAdjustment?->id,
                    'decrease_stock_adjustment_id' => $decreaseAdjustment?->id,
                ]);

                return [
                    'positive_count'         => $positiveLines->count(),
                    'negative_count'         => $negativeLines->count(),
                    'increase_adjustment_id' => $increaseAdjustment?->id,
                    'decrease_adjustment_id' => $decreaseAdjustment?->id,
                ];
            });
        } catch (RuntimeException $e) {
            return redirect(url('/stock-counts/' . $stockCountSession->id))
                ->with('error', $e->getMessage());
        }

        if (($result['positive_count'] + $result['negative_count']) === 0) {
            $message = 'Stock count posted successfully. No variance was found.';
        } else {
            $message = 'Stock count posted successfully. '
                . $result['positive_count'] . ' gain line(s), '
                . $result['negative_count'] . ' loss line(s).';
        }

        return redirect(url('/stock-counts/' . $stockCountSession->id))
            ->with('success', $message);
    }

    private function createStockCountAdjustment(
        StockCountSession $session,
        string $adjustmentType,
        string $movementType,
        $lines,
        InventoryService $inventoryService,
    ): StockAdjustment {
        $adjustment = StockAdjustment::create([
            'adjustment_no'     => $this->nextAdjustmentNo(),
            'branch_id'         => $session->branch_id,
            'adjustment_type'   => $adjustmentType,
            'adjustment_date'   => now()->toDateString(),
            'status'            => 'posted',
            'posted_by_user_id' => auth('tenant')->id(),
            'posted_at'         => now(),
            'notes'             => 'Stock count ' . $session->count_no . ' variance posting.',
        ]);

        foreach ($lines as $line) {
            $this->postStockCountAdjustmentLine(
                session: $session,
                adjustment: $adjustment,
                line: $line,
                adjustmentType: $adjustmentType,
                movementType: $movementType,
                inventoryService: $inventoryService,
            );
        }

        return $adjustment;
    }

    private function postStockCountAdjustmentLine(
        StockCountSession $session,
        StockAdjustment $adjustment,
        StockCountLine $line,
        string $adjustmentType,
        string $movementType,
        InventoryService $inventoryService,
    ): void {
        if (!$line->product) {
            throw new RuntimeException('Stock count line has missing product.');
        }

        $quantity = round(abs((float) $line->variance_quantity), 3);

        if ($quantity <= 0.0004) {
            return;
        }

        $unitCost = (float) ($line->average_cost ?? 0);

        $adjustmentLine = StockAdjustmentLine::create([
            'stock_adjustment_id' => $adjustment->id,
            'product_id'          => $line->product_id,
            'product_variant_id'  => $line->product_variant_id,
            'quantity'            => $quantity,
            'unit_cost'           => $unitCost,
            'notes'               => 'From stock count ' . $session->count_no,
        ]);

        if ($adjustmentType === 'increase') {
            $ledger = $inventoryService->postIn(
                branch: $session->branch,
                product: $line->product,
                variant: $line->variant,
                quantity: $quantity,
                unitCost: $unitCost,
                movementType: $movementType,
                referenceType: 'stock_adjustment',
                referenceId: (int) $adjustment->id,
                referenceNo: $adjustment->adjustment_no,
                batchNo: null,
                expiryDate: null,
                notes: 'Stock count ' . $session->count_no . ' gain',
                userId: auth('tenant')->id(),
            );

            if ($ledger?->inventory_batch_id) {
                $adjustmentLine->update(['inventory_batch_id' => $ledger->inventory_batch_id]);
            }

            return;
        }

        if ($adjustmentType === 'decrease') {
            $inventoryService->postOutFefo(
                branch: $session->branch,
                product: $line->product,
                variant: $line->variant,
                quantity: $quantity,
                movementType: $movementType,
                referenceType: 'stock_adjustment',
                referenceId: (int) $adjustment->id,
                referenceNo: $adjustment->adjustment_no,
                notes: 'Stock count ' . $session->count_no . ' loss',
                userId: auth('tenant')->id(),
            );

            return;
        }

        throw new RuntimeException('Unsupported stock count adjustment type.');
    }

    private function nextAdjustmentNo(): string
    {
        return 'ADJ-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }

    private function stockSnapshot(int $branchId, int $productId, ?int $variantId): array
    {
        // BUG-051 FIX: SUM across all batches for this product/variant at this branch
        // so multi-batch products (expiry items) show the correct total on-hand, not just
        // one batch row. Weighted average cost is derived from total value / total qty.
        $rows = StockBalance::where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->when($variantId, fn ($q) => $q->where('product_variant_id', $variantId),
                              fn ($q) => $q->whereNull('product_variant_id'))
            ->get(['quantity_on_hand', 'average_cost']);

        $totalQty   = $rows->sum(fn ($r) => (float) $r->quantity_on_hand);
        $totalValue = $rows->sum(fn ($r) => (float) $r->quantity_on_hand * (float) $r->average_cost);
        $avgCost    = $totalQty > 0 ? round($totalValue / $totalQty, 4) : 0.0;

        return [
            'quantity'     => round($totalQty, 3),
            'average_cost' => $avgCost,
        ];
    }

    private function nextCountNo(): string
    {
        return 'SC-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }
}
