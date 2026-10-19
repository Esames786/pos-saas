<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\InventoryBatch;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Services\Inventory\InventoryService;
use App\Support\PackLines;
use App\Support\PackUnits;
use App\Support\ScreenAccess;
use App\Support\TenantFeatures;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $query = StockBalance::query()
            ->with(['branch', 'product', 'variant', 'batch'])
            ->where('quantity_on_hand', '!=', 0)
            ->latest();

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        return view('tenant.inventory.index', [
            'balances' => $query->paginate(15)->withQueryString(),
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function movements(Request $request)
    {
        $query = StockLedger::query()
            ->with(['branch', 'product', 'variant', 'batch', 'createdBy'])
            ->latest();

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('movement_type')) {
            $query->where('movement_type', $request->movement_type);
        }

        return view('tenant.inventory.movements', [
            'ledgers'  => $query->paginate(20)->withQueryString(),
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function batches(Request $request)
    {
        $query = InventoryBatch::query()
            ->with(['branch', 'product', 'variant'])
            ->latest();

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('expiry_status')) {
            if ($request->expiry_status === 'expired') {
                $query->whereNotNull('expiry_date')->whereDate('expiry_date', '<', now());
            }

            if ($request->expiry_status === 'expiring') {
                $query->whereNotNull('expiry_date')
                    ->whereBetween('expiry_date', [
                        now()->toDateString(),
                        now()->addDays(30)->toDateString(),
                    ]);
            }
        }

        // PURCHASING-V2-2: with the new purchase screens on, the page also finds by item or batch, shows the
        // stock left in each batch (in its packs), and makes / corrects batches. Off = exactly as before.
        $manage = TenantFeatures::on(TenantFeatures::PURCHASING_V2);
        if ($manage && filled($q = trim((string) $request->input('q')))) {
            $query->where(fn ($w) => $w->where('batch_no', 'like', "%{$q}%")
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$q}%")->orWhere('sku', 'like', "%{$q}%")));
        }

        $batches = $query->paginate(15)->withQueryString();
        $stockWords = [];
        if ($manage) {
            $batches->getCollection()->loadMissing(['product.unit', 'product.packUnits']);
            $qty = StockBalance::whereIn('inventory_batch_id', $batches->getCollection()->pluck('id'))
                ->groupBy('inventory_batch_id')->selectRaw('inventory_batch_id, SUM(quantity_on_hand) as qty')->pluck('qty', 'inventory_batch_id');
            $packsOn = PackUnits::enabled();
            foreach ($batches as $b) {
                $p = $b->product;
                $stockWords[$b->id] = $p ? PackUnits::describe($packsOn ? $p->packUnits : [], (float) ($qty[$b->id] ?? 0), $p->unit?->name ?: 'Piece') : '—';
            }
        }

        return view('tenant.inventory.batches', [
            'batches'    => $batches,
            'branches'   => Branch::where('status', 'active')->orderBy('name')->get(),
            'manage'     => $manage,
            'stockWords' => $stockWords,
        ]);
    }

    /**
     * PURCHASING-V2-2 — a batch made before the goods come (owner: "system mai already batch banane ka option"),
     * so the GRN's batch box offers it with its expiry already known. No stock moves; it only names the batch.
     */
    public function storeBatch(Request $request, InventoryService $inventory)
    {
        ScreenAccess::purchasing(['tenant.inventory.batches']);

        $data = $request->validate([
            'product_id' => 'required|integer|exists:tenant.products,id',
            'branch_id'  => 'required|integer|exists:tenant.branches,id',
            'batch_no'   => 'required|string|max:100',
            'expiry'     => 'nullable|string|max:10',
        ], ['product_id.required' => 'Pick the item.', 'batch_no.required' => 'Write the batch number printed on the box.']);

        $product = Product::findOrFail($data['product_id']);
        $branch = Branch::findOrFail($data['branch_id']);
        $expiry = PackLines::expiry($data['expiry'] ?? null);
        if ($expiry === false) {
            throw ValidationException::withMessages(['expiry' => 'Write the expiry as month/year, for example 09/28.']);
        }
        if (! $expiry && $product->has_expiry) {
            throw ValidationException::withMessages(['expiry' => 'This item has an expiry — write it (month/year).']);
        }

        $batchNo = strtoupper(trim($data['batch_no']));
        $existing = InventoryBatch::where('branch_id', $branch->id)->where('product_id', $product->id)->where('batch_no', $batchNo)->first();
        if ($existing) {
            throw ValidationException::withMessages(['batch_no' => "Batch {$batchNo} is already there" . ($existing->expiry_date ? ' (expiry ' . $existing->expiry_date->format('m/y') . ')' : '') . '.']);
        }

        $variant = $inventory->resolveVariant($product, null);
        $batch = InventoryBatch::create([
            'batch_key'          => $inventory->batchKey($branch->id, $product->id, $variant?->id, $batchNo),
            'branch_id'          => $branch->id,
            'product_id'         => $product->id,
            'product_variant_id' => $variant?->id,
            'batch_no'           => $batchNo,
            'expiry_date'        => $expiry ?: null,
            'received_date'      => null,
            'unit_cost'          => 0,
            'status'             => 'active',
        ]);

        return response()->json(['id' => $batch->id, 'batch_no' => $batch->batch_no, 'expiry' => $batch->expiry_date?->format('m/y'), 'product' => $product->name, 'branch' => $branch->name], 201);
    }

    /**
     * PURCHASING-V2-2 — correct a batch's expiry (a typing mistake, or the supplier's corrected date). The batch
     * keeps one expiry for every receipt into it; the GRN papers keep what they said on the day.
     */
    public function updateBatchExpiry(Request $request, InventoryBatch $batch)
    {
        ScreenAccess::purchasing(['tenant.inventory.batches']);

        $expiry = PackLines::expiry($request->input('expiry'));
        if (! $expiry) {
            throw ValidationException::withMessages(['expiry' => 'Write the expiry as month/year, for example 09/28.']);
        }

        $batch->expiry_date = $expiry;
        if ($batch->status === 'expired' && \Illuminate\Support\Carbon::parse($expiry)->isFuture()) {
            $batch->status = 'active';
        }
        $batch->save();

        return response()->json(['id' => $batch->id, 'expiry' => $batch->expiry_date->format('m/y'), 'expiry_date' => $batch->expiry_date->format('d M Y')]);
    }

    public function lowStock(Request $request)
    {
        $products = Product::query()
            ->with(['defaultVariant', 'stockBalances'])
            ->where('is_stock_tracked', true)
            ->whereHas('defaultVariant', fn ($q) => $q->where('reorder_level', '>', 0))
            ->get()
            ->filter(function (Product $product) {
                $qty     = $product->stockBalances->sum('quantity_on_hand');
                $reorder = (float) ($product->defaultVariant?->reorder_level ?? 0);

                return $reorder > 0 && $qty <= $reorder;
            });

        return view('tenant.inventory.low-stock', compact('products'));
    }

    public function expiryAlerts(Request $request)
    {
        $days = (int) $request->input('days', 30);

        $batches = InventoryBatch::query()
            ->with(['branch', 'product', 'variant', 'balances'])
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($days))
            ->orderBy('expiry_date')
            ->paginate(20)
            ->withQueryString();

        return view('tenant.inventory.expiry-alerts', compact('batches', 'days'));
    }
}
