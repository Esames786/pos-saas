<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\GoodsReceiptLine;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\Supplier;
use App\Services\Purchasing\PurchasingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoodsReceiptController extends Controller
{
    public function __construct(protected PurchasingService $purchasingService) {}

    public function index(Request $request)
    {
        $query = GoodsReceipt::with(['branch', 'supplier', 'purchaseOrder', 'postedBy'])
            ->orderByDesc('receipt_date')
            ->orderByDesc('id');

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        $receipts  = $query->paginate(20)->withQueryString();
        $branches  = Branch::orderBy('name')->get();
        $suppliers = Supplier::where('status', 'active')->orderBy('name')->get();

        return view('tenant.goods-receipts.index', compact('receipts', 'branches', 'suppliers'));
    }

    public function create(Request $request)
    {
        $branches     = Branch::orderBy('name')->get();
        $suppliers    = Supplier::where('status', 'active')->orderBy('name')->get();
        $products     = Product::with(['unit', 'variants'])
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
        $openOrders   = PurchaseOrder::with(['supplier', 'lines.product'])
            ->whereIn('status', ['approved'])
            ->orderByDesc('order_date')
            ->get();
        $selectedPo   = null;
        $prefillLines = [];

        if ($request->filled('purchase_order_id')) {
            $selectedPo = PurchaseOrder::with(['supplier', 'branch', 'lines.product', 'lines.variant'])
                ->find($request->purchase_order_id);

            if ($selectedPo) {
                // Already-received qty per PO line (supports partial receiving).
                $received = GoodsReceiptLine::whereIn('purchase_order_line_id', $selectedPo->lines->pluck('id'))
                    ->selectRaw('purchase_order_line_id, SUM(quantity_received) as qty')
                    ->groupBy('purchase_order_line_id')
                    ->pluck('qty', 'purchase_order_line_id');

                foreach ($selectedPo->lines as $line) {
                    $remaining = (float) $line->quantity_ordered - (float) ($received[$line->id] ?? 0);
                    if ($remaining <= 0) {
                        continue; // fully received — skip
                    }

                    $prefillLines[] = [
                        'purchase_order_line_id' => $line->id,
                        'product_id'             => $line->product_id,
                        'product_variant_id'     => $line->product_variant_id,
                        'quantity_received'      => 0 + $remaining,
                        'unit_cost'              => 0 + (float) $line->unit_cost,
                        'discount_amount'        => 0 + (float) $line->discount_amount,
                        'tax_amount'             => 0 + (float) $line->tax_amount,
                        'batch_no'               => '',
                        'expiry_date'            => '',
                    ];
                }
            }
        }

        return view('tenant.goods-receipts.create', [
            'branches'      => $branches,
            'suppliers'     => $suppliers,
            'products'      => $products,
            'openOrders'    => $openOrders,
            'purchaseOrder' => $selectedPo,
            'prefillLines'  => $prefillLines,
        ]);
    }

    public function store(Request $request)
    {
        // Drop fully-blank rows before validation (robust even if client JS is bypassed).
        $request->merge([
            'lines' => collect($request->input('lines', []))
                ->filter(fn ($l) => !empty($l['product_id'] ?? null))
                ->values()
                ->all(),
        ]);

        $data = $request->validate([
            'branch_id'                    => 'required|exists:tenant.branches,id',
            'supplier_id'                  => 'required|exists:tenant.suppliers,id',
            'purchase_order_id'            => 'nullable|exists:tenant.purchase_orders,id',
            'receipt_date'                 => 'required|date',
            'notes'                        => 'nullable|string|max:1000',
            'lines'                        => 'required|array|min:1',
            'lines.*.product_id'           => 'required|exists:tenant.products,id',
            'lines.*.product_variant_id'   => 'nullable|exists:tenant.product_variants,id',
            'lines.*.purchase_order_line_id' => 'nullable|exists:tenant.purchase_order_lines,id',
            'lines.*.batch_no'             => 'nullable|string|max:100',
            'lines.*.expiry_date'          => 'nullable|date',
            'lines.*.quantity_received'    => 'required|numeric|min:0.001',
            'lines.*.unit_cost'            => 'required|numeric|min:0',
            'lines.*.discount_amount'      => 'nullable|numeric|min:0',
            'lines.*.tax_amount'           => 'nullable|numeric|min:0',
            'lines.*.notes'                => 'nullable|string|max:500',
        ]);

        $validLines = collect($data['lines'])->filter(fn($l) => !empty($l['product_id']));
        if ($validLines->isEmpty()) {
            return back()->withErrors(['lines' => 'At least one product line is required.'])->withInput();
        }

        // Conditional batch/expiry: required only when the selected product tracks them.
        $productMap = Product::whereIn('id', $validLines->pluck('product_id'))->get()->keyBy('id');
        foreach ($validLines->values() as $i => $line) {
            $product = $productMap[$line['product_id']] ?? null;
            if (! $product) {
                continue;
            }
            if ($product->requires_batch && empty($line['batch_no'])) {
                throw ValidationException::withMessages([
                    "lines.$i.batch_no" => "Batch number is required for {$product->name}.",
                ]);
            }
            if ($product->has_expiry && empty($line['expiry_date'])) {
                throw ValidationException::withMessages([
                    "lines.$i.expiry_date" => "Expiry date is required for {$product->name}.",
                ]);
            }
        }

        $userId = auth('tenant')->id();

        DB::connection('tenant')->transaction(function () use ($data, $validLines, $userId) {
            $grn = GoodsReceipt::create([
                'grn_no'            => $this->purchasingService->nextGrnNo(),
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'branch_id'         => $data['branch_id'],
                'supplier_id'       => $data['supplier_id'],
                'receipt_date'      => $data['receipt_date'],
                'status'            => 'posted',
                'notes'             => $data['notes'] ?? null,
                'posted_by_user_id' => $userId,
                'posted_at'         => now(),
            ]);

            foreach ($validLines as $line) {
                GoodsReceiptLine::create([
                    'goods_receipt_id'        => $grn->id,
                    'purchase_order_line_id'  => $line['purchase_order_line_id'] ?? null,
                    'product_id'              => $line['product_id'],
                    'product_variant_id'      => $line['product_variant_id'] ?? null,
                    'batch_no'                => $line['batch_no'] ?? null,
                    'expiry_date'             => $line['expiry_date'] ?? null,
                    'quantity_received'       => $line['quantity_received'],
                    'unit_cost'               => $line['unit_cost'],
                    'discount_amount'         => $line['discount_amount'] ?? 0,
                    'tax_amount'              => $line['tax_amount'] ?? 0,
                    'notes'                   => $line['notes'] ?? null,
                ]);
            }

            $grn->load('lines.product', 'lines.variant', 'branch');
            $this->purchasingService->postGrn($grn, $userId);

            if ($grn->purchase_order_id) {
                $po = PurchaseOrder::with('lines')->find($grn->purchase_order_id);
                if ($po && $po->status === 'approved') {
                    // BUG-062 FIX: only set 'received' when ALL PO lines are fully received.
                    // Compare total ordered vs total received across ALL GRNs for this PO.
                    $totalOrdered  = (float) $po->lines->sum('quantity_ordered');
                    $totalReceived = \App\Models\Tenant\GoodsReceiptLine::query()
                        ->join('goods_receipts', 'goods_receipt_lines.goods_receipt_id', '=', 'goods_receipts.id')
                        ->where('goods_receipts.purchase_order_id', $po->id)
                        ->where('goods_receipts.status', 'posted')
                        ->sum('goods_receipt_lines.quantity_received');

                    $newPoStatus = ($totalOrdered > 0 && $totalReceived >= $totalOrdered)
                        ? 'received'
                        : 'partially_received';

                    $po->update(['status' => $newPoStatus]);
                }
            }
        });

        return redirect(url('/goods-receipts'))->with('status', 'Goods receipt posted.');
    }

    public function show(GoodsReceipt $goodsReceipt)
    {
        $goodsReceipt->load([
            'branch', 'supplier', 'purchaseOrder', 'postedBy',
            'lines.product', 'lines.variant',
        ]);

        $ledgers = \App\Models\Tenant\StockLedger::where('reference_type', GoodsReceipt::class)
            ->where('reference_id', $goodsReceipt->id)
            ->with(['branch', 'product'])
            ->get();

        return view('tenant.goods-receipts.show', compact('goodsReceipt', 'ledgers'));
    }
}
