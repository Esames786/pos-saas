<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\StockTransfer;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockTransferController extends Controller
{
    public function index(Request $request)
    {
        $query = StockTransfer::query()
            ->with(['fromBranch', 'toBranch', 'postedBy'])
            ->latest();

        if ($request->filled('branch_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('from_branch_id', $request->branch_id)
                    ->orWhere('to_branch_id', $request->branch_id);
            });
        }

        return view('tenant.stock-transfers.index', [
            'transfers' => $query->paginate(15)->withQueryString(),
            'branches'  => Branch::where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('tenant.stock-transfers.create', [
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(),
            'products' => Product::with(['unit', 'variants'])
                ->where('is_stock_tracked', true)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request, InventoryService $inventoryService)
    {
        $data = $request->validate([
            'from_branch_id' => ['required', 'exists:branches,id', 'different:to_branch_id'],
            'to_branch_id'   => ['required', 'exists:branches,id'],
            'transfer_date'  => ['required', 'date'],
            'notes'          => ['nullable', 'string'],

            'lines'                      => ['required', 'array'],
            'lines.*.product_id'         => ['nullable', 'exists:products,id'],
            'lines.*.product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'lines.*.quantity'           => ['nullable', 'numeric', 'min:0.001'],
            'lines.*.unit_cost'          => ['nullable', 'numeric', 'min:0'],
        ]);

        $lines = collect($data['lines'])
            ->filter(fn ($line) => !empty($line['product_id']) && !empty($line['quantity']))
            ->values();

        if ($lines->isEmpty()) {
            return back()->withErrors(['lines' => 'At least one valid product line is required.'])->withInput();
        }

        try {
            DB::transaction(function () use ($data, $lines, $inventoryService) {
                $fromBranch = Branch::findOrFail($data['from_branch_id']);
                $toBranch   = Branch::findOrFail($data['to_branch_id']);

                $transfer = StockTransfer::create([
                    'transfer_no'        => $this->nextTransferNo(),
                    'from_branch_id'     => $fromBranch->id,
                    'to_branch_id'       => $toBranch->id,
                    'transfer_date'      => $data['transfer_date'],
                    'status'             => 'posted',
                    'posted_by_user_id'  => auth('tenant')->id(),
                    'posted_at'          => now(),
                    'notes'              => $data['notes'] ?? null,
                ]);

                foreach ($lines as $line) {
                    $product  = Product::findOrFail($line['product_id']);
                    $variant  = $inventoryService->resolveVariant($product, $line['product_variant_id'] ?? null);
                    $quantity = (float) $line['quantity'];
                    $unitCost = (float) ($line['unit_cost'] ?? 0);

                    $transfer->lines()->create([
                        'product_id'         => $product->id,
                        'product_variant_id' => $variant?->id,
                        'quantity'           => $quantity,
                        'unit_cost'          => $unitCost,
                    ]);

                    $inventoryService->transfer(
                        fromBranch: $fromBranch,
                        toBranch: $toBranch,
                        product: $product,
                        variant: $variant,
                        quantity: $quantity,
                        unitCost: $unitCost,
                        referenceType: 'stock_transfer',
                        referenceId: $transfer->id,
                        referenceNo: $transfer->transfer_no,
                        notes: $data['notes'] ?? null,
                        userId: auth('tenant')->id()
                    );
                }
            });
        } catch (RuntimeException $e) {
            return back()->withErrors(['stock' => $e->getMessage()])->withInput();
        }

        return redirect('/stock-transfers')->with('status', 'Stock transfer posted successfully.');
    }

    public function show(StockTransfer $stockTransfer)
    {
        $stockTransfer->load(['fromBranch', 'toBranch', 'postedBy', 'lines.product', 'lines.variant']);

        $ledgers = StockLedger::with(['branch', 'product', 'variant', 'batch'])
            ->where('reference_type', 'stock_transfer')
            ->where('reference_id', $stockTransfer->id)
            ->latest()
            ->get();

        return view('tenant.stock-transfers.show', compact('stockTransfer', 'ledgers'));
    }

    private function nextTransferNo(): string
    {
        return 'TRF-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }
}
