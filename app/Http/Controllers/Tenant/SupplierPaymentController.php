<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierPayment;
use App\Services\Purchasing\PurchasingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierPaymentController extends Controller
{
    public function __construct(protected PurchasingService $purchasingService) {}

    public function index(Request $request)
    {
        $query = SupplierPayment::with(['supplier', 'branch', 'bill', 'postedBy'])
            ->orderByDesc('payment_date')
            ->orderByDesc('id');

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        $payments  = $query->paginate(20)->withQueryString();
        $branches  = Branch::orderBy('name')->get();
        $suppliers = Supplier::where('status', 'active')->orderBy('name')->get();

        return view('tenant.supplier-payments.index', compact('payments', 'branches', 'suppliers'));
    }

    public function create(Request $request)
    {
        $branches  = Branch::orderBy('name')->get();
        $suppliers = Supplier::where('status', 'active')->orderBy('name')->get();
        $bills     = PurchaseBill::whereIn('status', ['posted', 'partial'])
            ->with('supplier')
            ->orderByDesc('bill_date')
            ->get();

        $bill = null;
        if ($request->filled('purchase_bill_id')) {
            $bill = PurchaseBill::find($request->purchase_bill_id);
        }

        return view('tenant.supplier-payments.create', compact('branches', 'suppliers', 'bills', 'bill'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id'      => 'required|exists:tenant.suppliers,id',
            'branch_id'        => 'required|exists:tenant.branches,id',
            'purchase_bill_id' => 'nullable|exists:tenant.purchase_bills,id',
            'payment_date'     => 'required|date',
            'amount'           => 'required|numeric|min:0.01',
            'payment_method'   => 'required|in:cash,bank_transfer,cheque,card,other',
            'reference_no'     => 'nullable|string|max:100',
            'bank_name'        => 'nullable|string|max:100',
            'account_no'       => 'nullable|string|max:100',
            'transaction_ref'  => 'nullable|string|max:100',
            'cheque_no'        => 'nullable|string|max:100',
            'cheque_date'      => 'nullable|date',
            'notes'            => 'nullable|string|max:1000',
        ]);

        $userId = auth('tenant')->id();

        DB::connection('tenant')->transaction(function () use ($data, $userId) {
            $supplierPayment = SupplierPayment::create([
                ...$data,
                'payment_no'        => $this->purchasingService->nextPaymentNo(),
                'posted_by_user_id' => $userId,
            ]);

            $supplierPayment->load('supplier');
            $this->purchasingService->postPayment($supplierPayment, $userId);
        });

        return redirect(url('/supplier-payments'))->with('status', 'Payment posted.');
    }

    public function show(SupplierPayment $supplierPayment)
    {
        $supplierPayment->load(['supplier', 'branch', 'bill', 'postedBy']);
        return view('tenant.supplier-payments.show', compact('supplierPayment'));
    }
}
