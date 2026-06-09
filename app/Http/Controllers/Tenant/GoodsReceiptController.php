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

        if ($request->filled('purchase_order_id')) {
            $selectedPo = PurchaseOrder::with(['supplier', 'branch', 'lines.product', 'lines.variant'])
                ->find($request->purchase_order_id);
        }

        return view('tenant.goods-receipts.create', [
            'branches'      => $branches,
            'suppliers'     => $suppliers,
            'products'      => $products,
            'openOrders'    => $openOrders,
            'purchaseOrder' => $selectedPo,
        ]);
    }

    public function store(Request $request)
    {
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
                $po = PurchaseOrder::find($grn->purchase_order_id);
                if ($po && $po->status === 'approved') {
                    $po->update(['status' => 'received']);
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
