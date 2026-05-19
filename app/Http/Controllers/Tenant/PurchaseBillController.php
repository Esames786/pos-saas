<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchaseBillLine;
use App\Models\Tenant\Supplier;
use App\Services\Purchasing\PurchasingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            $goodsReceipt = GoodsReceipt::with(['supplier', 'branch', 'lines.product', 'lines.variant'])
                ->whereDoesntHave('bill')
                ->find($request->goods_receipt_id);
        }

        return view('tenant.purchase-bills.create', compact('receipts', 'goodsReceipt'));
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
        ]);

        $grn = GoodsReceipt::with(['lines.product', 'supplier', 'branch'])
            ->findOrFail($data['goods_receipt_id']);

        if ($grn->bill) {
            return back()->withErrors(['goods_receipt_id' => 'A bill already exists for this GRN.'])->withInput();
        }

        $userId = auth('tenant')->id();

        DB::connection('tenant')->transaction(function () use ($data, $grn, $userId) {
            $subtotal   = $grn->lines->sum(fn($l) => $l->quantity_received * $l->unit_cost);
            $discTotal  = (float) ($data['discount_amount'] ?? 0);
            $taxTotal   = (float) ($data['tax_amount'] ?? 0);
            $grandTotal = $subtotal - $discTotal + $taxTotal;

            $purchaseBill = PurchaseBill::create([
                'bill_no'             => $this->purchasingService->nextBillNo(),
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                'supplier_id'         => $grn->supplier_id,
                'branch_id'           => $grn->branch_id,
                'purchase_order_id'   => $grn->purchase_order_id,
                'goods_receipt_id'    => $grn->id,
                'bill_date'           => $data['bill_date'],
                'due_date'            => $data['due_date'] ?? null,
                'status'              => 'posted',
                'subtotal'            => $subtotal,
                'discount_total'      => $discTotal,
                'tax_total'           => $taxTotal,
                'grand_total'         => $grandTotal,
                'amount_paid'         => 0,
                'balance_due'         => $grandTotal,
                'notes'               => $data['notes'] ?? null,
                'posted_by_user_id'   => $userId,
                'posted_at'           => now(),
            ]);

            foreach ($grn->lines as $line) {
                $lineTotal = $line->quantity_received * $line->unit_cost;
                PurchaseBillLine::create([
                    'purchase_bill_id'   => $purchaseBill->id,
                    'product_id'         => $line->product_id,
                    'product_variant_id' => $line->product_variant_id,
                    'quantity'           => $line->quantity_received,
                    'unit_cost'          => $line->unit_cost,
                    'discount_amount'    => 0,
                    'tax_amount'         => 0,
                    'line_total'         => $lineTotal,
                ]);
            }

            $purchaseBill->load('supplier');
            $this->purchasingService->postBill($purchaseBill, $userId);
        });

        return redirect(url('/purchase-bills'))->with('status', 'Purchase bill posted.');
    }

    public function show(PurchaseBill $purchaseBill)
    {
        $purchaseBill->load([
            'supplier', 'branch', 'purchaseOrder', 'goodsReceipt',
            'lines.product', 'lines.variant', 'postedBy', 'payments.postedBy',
        ]);
        return view('tenant.purchase-bills.show', compact('purchaseBill'));
    }
}
