<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockCountLine;
use App\Models\Tenant\StockCountSession;
use Illuminate\Http\Request;

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

    public function post(StockCountSession $stockCountSession)
    {
        // 13F-2 will implement actual variance posting into inventory ledger.
        return redirect(url('/stock-counts/' . $stockCountSession->id))
            ->with('warning', 'Posting stock count variance is not yet implemented. Coming in Prompt 13F-2.');
    }

    private function stockSnapshot(int $branchId, int $productId, ?int $variantId): array
    {
        $query = StockBalance::where('branch_id', $branchId)
            ->where('product_id', $productId);

        if ($variantId) {
            $query->where('product_variant_id', $variantId);
        } else {
            $query->whereNull('product_variant_id');
        }

        $balance = $query->first();

        return [
            'quantity'     => $balance ? (float) $balance->quantity_on_hand : 0.0,
            'average_cost' => $balance ? (float) $balance->average_cost : 0.0,
        ];
    }

    private function nextCountNo(): string
    {
        return 'SC-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }
}
