<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierPayment;
use App\Services\Finance\SupplierPayableService;
use App\Services\Purchasing\PurchasingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierPaymentController extends Controller
{
    public function __construct(
        protected PurchasingService $purchasingService,
        protected SupplierPayableService $supplierPayable,
    ) {}

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

        $cashBankAccounts = CashBankAccount::where('is_active', true)->orderBy('code')->get();

        return view('tenant.supplier-payments.create', compact('branches', 'suppliers', 'bills', 'bill', 'cashBankAccounts'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id'          => 'required|exists:tenant.suppliers,id',
            'branch_id'            => 'required|exists:tenant.branches,id',
            'cash_bank_account_id' => ['nullable', Rule::exists('tenant.cash_bank_accounts', 'id')->where('is_active', true)],
            'purchase_bill_id'     => 'nullable|exists:tenant.purchase_bills,id',
            'payment_date'         => 'required|date',
            'amount'               => 'required|numeric|min:0.01',
            'payment_method'       => 'required|in:cash,bank_transfer,cheque,card,other',
            'reference_no'         => 'nullable|string|max:100',
            'bank_name'            => 'nullable|string|max:100',
            'account_no'           => 'nullable|string|max:100',
            'transaction_ref'      => 'nullable|string|max:100',
            'cheque_no'            => 'nullable|string|max:100',
            'cheque_date'          => 'nullable|date',
            'notes'                => 'nullable|string|max:1000',
        ]);

        // recordPayment posts the supplier ledger + bill (existing behavior) and, when a
        // cash/bank account is selected, also writes the cash/bank money-out transaction.
        $this->supplierPayable->recordPayment($data, auth('tenant')->id());

        return redirect(url('/supplier-payments'))->with('status', 'Payment posted.');
    }

    public function show(SupplierPayment $supplierPayment)
    {
        $supplierPayment->load(['supplier', 'branch', 'bill', 'postedBy']);
        return view('tenant.supplier-payments.show', compact('supplierPayment'));
    }
}
