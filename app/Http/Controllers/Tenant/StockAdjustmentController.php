<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockLedger;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

class StockAdjustmentController extends Controller
{
    public function index(Request $request)
    {
        $query = StockAdjustment::query()
            ->with(['branch', 'postedBy'])
            ->latest();

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('adjustment_type')) {
            $query->where('adjustment_type', $request->adjustment_type);
        }

        return view('tenant.stock-adjustments.index', [
            'adjustments' => $query->paginate(15)->withQueryString(),
            'branches'    => Branch::where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        // INVENTORY-UX-1: products load via the AJAX picker now — no more
        // rendering the whole catalogue into every row's <select>.
        return view('tenant.stock-adjustments.create', [
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, InventoryService $inventoryService)
    {
        $data = $request->validate([
            'branch_id'       => ['required', 'exists:branches,id'],
            'adjustment_type' => ['required', Rule::in(['opening', 'increase', 'decrease', 'wastage'])],
            'adjustment_date' => ['required', 'date'],
            'notes'           => ['nullable', 'string'],

            'lines'                          => ['required', 'array'],
            'lines.*.product_id'             => ['nullable', 'exists:products,id'],
            'lines.*.product_variant_id'     => ['nullable', 'exists:product_variants,id'],
            'lines.*.batch_no'               => ['nullable', 'string', 'max:100'],
            'lines.*.expiry_date'            => ['nullable', 'date'],
            'lines.*.quantity'               => ['nullable', 'numeric', 'min:0.001'],
            'lines.*.unit_cost'              => ['nullable', 'numeric', 'min:0'],
            'lines.*.notes'                  => ['nullable', 'string'],
        ]);

        $lines = collect($data['lines'])
            ->filter(fn ($line) => !empty($line['product_id']) && !empty($line['quantity']))
            ->values();

        if ($lines->isEmpty()) {
            return back()->withErrors(['lines' => 'At least one valid product line is required.'])->withInput();
        }

        try {
            DB::transaction(function () use ($data, $lines, $inventoryService) {
                $branch = Branch::findOrFail($data['branch_id']);

                $adjustment = StockAdjustment::create([
                    'adjustment_no'      => $this->nextAdjustmentNo(),
                    'branch_id'          => $branch->id,
                    'adjustment_type'    => $data['adjustment_type'],
                    'adjustment_date'    => $data['adjustment_date'],
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

                    $adjustmentLine = $adjustment->lines()->create([
                        'product_id'         => $product->id,
                        'product_variant_id' => $variant?->id,
                        'batch_no'           => $line['batch_no'] ?? null,
                        'expiry_date'        => $line['expiry_date'] ?? null,
                        'quantity'           => $quantity,
                        'unit_cost'          => $unitCost,
                        'notes'              => $line['notes'] ?? null,
                    ]);

                    if (in_array($adjustment->adjustment_type, ['opening', 'increase'], true)) {
                        $movementType = $adjustment->adjustment_type === 'opening'
                            ? 'opening_stock'
                            : 'adjustment_in';

                        $ledger = $inventoryService->postIn(
                            branch: $branch,
                            product: $product,
                            variant: $variant,
                            quantity: $quantity,
                            unitCost: $unitCost,
                            movementType: $movementType,
                            referenceType: 'stock_adjustment',
                            referenceId: $adjustment->id,
                            referenceNo: $adjustment->adjustment_no,
                            batchNo: $line['batch_no'] ?? null,
                            expiryDate: $line['expiry_date'] ?? null,
                            notes: $line['notes'] ?? null,
                            userId: auth('tenant')->id()
                        );

                        $adjustmentLine->update(['inventory_batch_id' => $ledger->inventory_batch_id]);
                    } else {
                        $movementType = $adjustment->adjustment_type === 'wastage'
                            ? 'wastage'
                            : 'adjustment_out';

                        $inventoryService->postOutFefo(
                            branch: $branch,
                            product: $product,
                            variant: $variant,
                            quantity: $quantity,
                            movementType: $movementType,
                            referenceType: 'stock_adjustment',
                            referenceId: $adjustment->id,
                            referenceNo: $adjustment->adjustment_no,
                            notes: $line['notes'] ?? null,
                            userId: auth('tenant')->id()
                        );
                    }
                }
            });
        } catch (RuntimeException $e) {
            return back()->withErrors(['stock' => $e->getMessage()])->withInput();
        }

        return redirect('/stock-adjustments')->with('status', 'Stock adjustment posted successfully.');
    }

    public function show(StockAdjustment $stockAdjustment)
    {
        $stockAdjustment->load(['branch', 'postedBy', 'lines.product', 'lines.variant', 'lines.batch']);

        $ledgers = StockLedger::with(['product', 'variant', 'batch'])
            ->where('reference_type', 'stock_adjustment')
            ->where('reference_id', $stockAdjustment->id)
            ->latest()
            ->get();

        return view('tenant.stock-adjustments.show', compact('stockAdjustment', 'ledgers'));
    }

    private function nextAdjustmentNo(): string
    {
        return 'ADJ-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }
}
