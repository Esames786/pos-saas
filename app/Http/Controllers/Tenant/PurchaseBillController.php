<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\Supplier;
use App\Services\Purchasing\PurchaseBillService;
use App\Services\Purchasing\PurchasingService;
use App\Services\Purchasing\SupplierRunningAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            // SUPPLIER-CREDIT-CLARITY-1: ticked on review when the invoice uses the supplier's advance
            'confirm_credit'       => 'nullable|boolean',
        ], [
            'due_date.after_or_equal' => 'The due date cannot be before the invoice date.',
            'netted_amounts.*.numeric' => 'The amount deducted must be a number.',
        ]);

        $grn = GoodsReceipt::findOrFail($data['goods_receipt_id']);

        try {
            app(PurchaseBillService::class)->createFromReceipt($grn, $data, auth('tenant')->id(), $this->canReallocate($request), askCredit: true);
        } catch (\App\Services\Purchasing\CreditNotConfirmedException $e) {
            // the advance it would use was not ticked on the review: nothing was saved, the review opens again by the tick
            return back()->withErrors(['confirm_credit' => $e->getMessage()])->withInput();
        } catch (\RuntimeException $e) {
            // an invoice already recorded for this receipt, or its accounting entry could not post (nothing was saved)
            return back()->withErrors(['goods_receipt_id' => $e->getMessage()])->withInput();
        }

        return redirect(url('/purchase-bills'))->with('status', 'Supplier invoice recorded.');
    }

    public function show(PurchaseBill $purchaseBill, ?Request $request = null)
    {
        $request ??= request();   // the invoice stays the first argument: callers that pass only it keep working
        $purchaseBill->load([
            'supplier', 'branch', 'purchaseOrder', 'goodsReceipt',
            'lines.product', 'lines.variant', 'postedBy', 'payments.postedBy',
            'nettings.purchaseReturn',
        ]);

        // SUPPLIER-CREDIT-CLARITY-1: on a running account the invoice says which payments and returns paid it, and those
        // allowed to undo supplier money may move a part of one to another open invoice of the same supplier.
        $running = app(SupplierRunningAccountService::class);
        $runningAccount = $running->enabled();
        $paidBy = $runningAccount ? $running->paidBy($purchaseBill) : [];
        $moveTo = $runningAccount && $paidBy && $request->user('tenant')?->can('tenant.purchase-bills.move-credit')
            ? PurchaseBill::where('supplier_id', $purchaseBill->supplier_id)->whereKeyNot($purchaseBill->id)
                ->whereIn('status', ['posted', 'partial'])->where('balance_due', '>', 0.00005)
                ->orderBy('bill_date')->orderBy('id')->get(['id', 'bill_no', 'bill_date', 'balance_due'])
            : collect();
        $moves = $runningAccount && Schema::connection('tenant')->hasTable('supplier_credit_moves')
            ? DB::connection('tenant')->table('supplier_credit_moves')
                ->where(fn ($q) => $q->where('from_purchase_bill_id', $purchaseBill->id)->orWhere('to_purchase_bill_id', $purchaseBill->id))
                ->orderBy('id')->get()
            : collect();
        $billNos = PurchaseBill::whereIn('id', $moves->pluck('from_purchase_bill_id')->merge($moves->pluck('to_purchase_bill_id'))->unique())->pluck('bill_no', 'id');
        $movers = \App\Models\Tenant\User::whereIn('id', $moves->pluck('moved_by')->filter()->unique())->pluck('name', 'id');
        $movedFrom = fn (string $type) => $moves->where('source_type', $type)->pluck('source_id')->unique()->all();
        $sourceNos = [
            'payment' => \App\Models\Tenant\SupplierPayment::whereIn('id', $movedFrom('payment'))->pluck('payment_no', 'id'),
            'return'  => \App\Models\Tenant\PurchaseReturn::whereIn('id', $movedFrom('return'))->pluck('return_no', 'id'),
        ];

        return view('tenant.purchase-bills.show', compact('purchaseBill', 'runningAccount', 'paidBy', 'moveTo', 'moves', 'billNos', 'movers', 'sourceNos'));
    }

    /**
     * SUPPLIER-CREDIT-CLARITY-1 (owner, 26 Oct) — move what a payment or return pays on this invoice to another open
     * invoice of the same supplier. Only which invoice it settles changes: the supplier's balance, its ledger, cash and
     * the GL stay as they are. The reason and who moved it are kept.
     */
    public function moveCredit(Request $request, PurchaseBill $purchaseBill, SupplierRunningAccountService $running)
    {
        abort_unless($running->enabled(), 404);

        $data = $request->validate([
            'source'     => ['required', 'string', 'regex:/^(payment|return):\d+$/'],
            'to_bill_id' => 'required|integer|exists:tenant.purchase_bills,id',
            'amount'     => 'required|numeric|min:0.01',
            'reason'     => 'required|string|min:3|max:255',
        ], [
            'source.required'     => 'Choose the payment or return to move.',
            'to_bill_id.required' => 'Choose the invoice to move it to.',
            'amount.min'          => 'Enter an amount to move.',
            'reason.required'     => 'Write why it is being moved.',
        ]);
        [$type, $id] = explode(':', $data['source']);

        try {
            $running->moveAllocation($purchaseBill, $type, (int) $id, PurchaseBill::findOrFail($data['to_bill_id']),
                (float) $data['amount'], auth('tenant')->id(), $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['move' => $e->getMessage()])->withInput();
        }

        $to = PurchaseBill::find($data['to_bill_id']);

        return redirect(url('/purchase-bills/' . $purchaseBill->id))
            ->with('status', 'Moved ' . number_format((float) $data['amount'], 2) . ' to ' . $to?->bill_no . '. The supplier\'s balance did not change.');
    }
}
