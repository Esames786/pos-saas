<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\Supplier;
use App\Services\Purchasing\PurchaseBillService;
use App\Services\Purchasing\PurchasingService;
use Illuminate\Http\Request;

class PurchaseBillController extends Controller
{
    public function __construct(protected PurchasingService $purchasingService) {}

    public function index(Request $request)
    {
        $query = PurchaseBill::with(['branch', 'supplier', 'goodsReceipt', 'postedBy'])
            ->orderByDesc('bill_date')
            ->orderByDesc('id');

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        $bills     = $query->paginate(20)->withQueryString();
        $branches  = Branch::orderBy('name')->get();
        $suppliers = Supplier::where('status', 'active')->orderBy('name')->get();

        return view('tenant.purchase-bills.index', compact('bills', 'branches', 'suppliers'));
    }

    public function create(Request $request)
    {
        $receipts = GoodsReceipt::whereDoesntHave('bill')
            ->where('status', 'posted')
            ->with(['supplier', 'branch'])
            ->orderByDesc('receipt_date')
            ->get();

        $goodsReceipt = null;
        if ($request->filled('goods_receipt_id')) {
            $goodsReceipt = GoodsReceipt::with(['supplier', 'branch', 'lines.product.unit', 'lines.variant'])
                ->whereDoesntHave('bill')
                ->find($request->goods_receipt_id);
        }

        // SUPPLIER-NET-BILL-1: what the bill will post (summary), the returns it asks about, the bills their credit sits on.
        $preview = $goodsReceipt ? app(PurchaseBillService::class)->preview($goodsReceipt) : null;
        $canReallocate = $this->canReallocate($request);

        return view('tenant.purchase-bills.create', compact('receipts', 'goodsReceipt', 'preview', 'canReallocate'));
    }

    /** Moving a return credit off another bill: the people who may already undo supplier money (owner, 21 Oct). */
    private function canReallocate(Request $request): bool
    {
        return (bool) $request->user('tenant')?->can('tenant.supplier-payments.void');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'goods_receipt_id'    => 'required|exists:tenant.goods_receipts,id',
            'supplier_invoice_no' => 'nullable|string|max:100',
            'bill_date'           => 'required|date',
            'due_date'            => 'nullable|date|after_or_equal:bill_date',
            'discount_amount'     => 'nullable|numeric|min:0',
            'tax_amount'          => 'nullable|numeric|min:0',
            'notes'               => 'nullable|string|max:1000',
            // SUPPLIER-NET-BILL-1: asked only when goods from the receipt went back before its bill
            'returns_on_invoice'  => 'nullable|in:' . PurchaseBillService::SEPARATE . ',' . PurchaseBillService::NETTED,
            // net invoice: what the supplier deducted for each return (partial allowed; empty = the whole return)
            'netted_amounts'      => 'nullable|array',
            'netted_amounts.*'    => 'nullable|numeric|min:0',
            // ticked on review when a return's credit moves off another invoice
            'confirm_reallocation' => 'nullable|boolean',
        ], [
            'due_date.after_or_equal' => 'The due date cannot be before the invoice date.',
            'netted_amounts.*.numeric' => 'The amount deducted must be a number.',
        ]);

        $grn = GoodsReceipt::findOrFail($data['goods_receipt_id']);

        try {
            app(PurchaseBillService::class)->createFromReceipt($grn, $data, auth('tenant')->id(), $this->canReallocate($request));
        } catch (\RuntimeException $e) {
            // an invoice already recorded for this receipt, or its accounting entry could not post (nothing was saved)
            return back()->withErrors(['goods_receipt_id' => $e->getMessage()])->withInput();
        }

        return redirect(url('/purchase-bills'))->with('status', 'Supplier invoice recorded.');
    }

    public function show(PurchaseBill $purchaseBill)
    {
        $purchaseBill->load([
            'supplier', 'branch', 'purchaseOrder', 'goodsReceipt',
            'lines.product', 'lines.variant', 'postedBy', 'payments.postedBy',
            'nettings.purchaseReturn',
        ]);
        return view('tenant.purchase-bills.show', compact('purchaseBill'));
    }
}
