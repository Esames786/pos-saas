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

        // SUPPLIER-FINANCE-DIRECT-1 — Supplier Ledger ke "Record Payment" se aane par supplier
        // pehle se chuna hua hota hai. Bill ki koi zaroorat nahi: payment khuli balance par
        // seedha lagta hai (dekho SupplierPayableService::recordPayment ka docblock).
        // ⚠️ Naam `$supplierPreset` hai, `$supplier` NAHI — view me `@foreach($suppliers as $supplier)`
        // chalta hai aur wo loop variable is naam ko dhak deta, to preset chup-chaap gum ho jata.
        $supplierPreset = $request->filled('supplier_id')
            ? Supplier::find($request->integer('supplier_id'))
            : ($bill?->supplier);

        // Wapsi ka pata: ledger se aaye to ledger par lauto, warna payments ki list par.
        $returnTo = $request->input('from') === 'ledger' && $supplierPreset
            ? url('/suppliers/' . $supplierPreset->id . '/ledger')
            : url('/supplier-payments');

        return view('tenant.supplier-payments.create', compact(
            'branches', 'suppliers', 'bills', 'bill', 'cashBankAccounts', 'supplierPreset', 'returnTo'
        ));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id'          => 'required|exists:tenant.suppliers,id',
            'branch_id'            => 'required|exists:tenant.branches,id',
            // LAZMI, nullable nahi. Bina cash/bank account ke JournalPostingService::postSupplierPayment()
            // null laut-ta hai — yani subledger mein AP ghat jata aur GL mein waisa ka waisa reh jata.
            // Wohi farq jo requirement K mana karti hai. Ab recordPayment() us soorat mein poora
            // transaction palat deta hai, is liye shart yahan bhi saaf rakhi hai taake operator ko
            // form par sada jawab mile, 500 nahi.
            'cash_bank_account_id' => ['required', Rule::exists('tenant.cash_bank_accounts', 'id')->where('is_active', true)],
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

        // return_to sirf redirect ke liye hai, payment ka hissa nahi — is liye validate ke
        // BAAD alag padha jata hai aur $data me nahi jata (warna SupplierPayment::create()
        // use fillable samajh kar chhorta, aur ek chupa hua column-mismatch banta).


        // recordPayment EK transaction mein payment row + supplier subledger + bill (agar diya ho)
        // + cash/bank + GL journal, sab likhta hai. Purchase bill ki zaroorat NAHI.
        // Jo bhi hissa fail ho, poora palat jata hai — is liye yahan pakadna zaroori hai warna
        // operator ko 500 milta.
        try {
            $payment = $this->supplierPayable->recordPayment($data, auth('tenant')->id());
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        // Ledger se aaye the to wahin lauto — operator ko apni satar foran nazar aani chahiye.
        $back = $request->input('return_to');
        $target = ($back && str_starts_with((string) $back, url('/suppliers/')))
            ? $back
            : url('/supplier-payments');

        return redirect($target)->with('status', 'Payment ' . $payment->payment_no . ' posted.');
    }

    public function show(SupplierPayment $supplierPayment)
    {
        $supplierPayment->load(['supplier', 'branch', 'bill', 'postedBy']);
        return view('tenant.supplier-payments.show', compact('supplierPayment'));
    }
}
