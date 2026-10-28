<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchasingSetting;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierPayment;
use App\Services\Finance\SupplierPayableService;
use App\Services\Purchasing\PurchasingService;
use App\Services\Purchasing\SupplierRunningAccountService;
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

        // SUPPLIER-CREDIT-CLARITY-1: with the running account on, the screen asks where the payment goes and lists, before
        // Save, the invoices it pays — from these: each supplier's open invoices in the order the server pays them.
        $openBills = PurchasingSetting::supplierRunningAccount()
            ? PurchaseBill::whereIn('status', ['posted', 'partial'])->where('balance_due', '>', 0.00005)
                ->orderBy('supplier_id')->orderBy('bill_date')->orderBy('id')->get(['id', 'bill_no', 'supplier_id', 'bill_date', 'balance_due'])
            : collect();

        return view('tenant.supplier-payments.create', compact(
            'branches', 'suppliers', 'bills', 'bill', 'cashBankAccounts', 'supplierPreset', 'returnTo', 'openBills'
        ));
    }

    /**
     * SUPPLIER-CREDIT-CLARITY-1 — with the running account on: where this payment goes is the person's own choice (one
     * invoice, or the oldest open invoices first — nothing is chosen for them), and it posts only if the invoices it
     * pays are still the ones the screen listed. Otherwise nothing is saved and the screen lists them again.
     *
     * @return array<string, string>|null  errors to show, or null to go on
     */
    private function checkPlace(Request $request, array $data): ?array
    {
        $place = (string) $request->input('pay_to', '');
        if (! in_array($place, ['bill', 'oldest'], true)) {
            return ['pay_to' => 'Choose where this payment goes: one invoice, or the oldest open invoices first.'];
        }
        if ($place === 'bill' && empty($data['purchase_bill_id'])) {
            return ['purchase_bill_id' => 'Choose the invoice this payment is for.'];
        }

        $plan = app(SupplierRunningAccountService::class)->planSettle((int) $data['supplier_id'], (float) $data['amount'],
            $place === 'bill' ? (int) $data['purchase_bill_id'] : null);
        if (! $this->sameAsShown($plan, (string) $request->input('plan', ''))) {
            // the page comes back with today's open invoices and lists them again
            return ['plan' => 'Check where this payment goes (listed below), then press Post again.'];
        }

        return null;
    }

    /** The plan the page listed ("billId:amount;billId:amount|advance") against the server's — same invoices, same order, to the paisa. */
    private function sameAsShown(array $plan, string $shown): bool
    {
        [$lines, $advance] = array_pad(explode('|', $shown, 2), 2, '');
        $pairs = array_values(array_filter(explode(';', $lines), fn ($p) => $p !== ''));
        if (count($pairs) !== count($plan['lines']) || abs((float) $advance - $plan['advance']) > 0.011) {
            return false;
        }
        foreach ($plan['lines'] as $i => $line) {
            [$id, $amount] = array_pad(explode(':', $pairs[$i], 2), 2, '');
            if ((int) $id !== $line['bill_id'] || abs((float) $amount - $line['amount']) > 0.011) {
                return false;
            }
        }

        return true;
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

        // SUPPLIER-CREDIT-CLARITY-1: on account, the person says where it goes and sees the invoices it pays first.
        if (PurchasingSetting::supplierRunningAccount()) {
            if ($request->input('pay_to') === 'oldest') {
                $data['purchase_bill_id'] = null;
            }
            if ($errors = $this->checkPlace($request, $data)) {
                return back()->withInput()->withErrors($errors);
            }
        }

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
        $supplierPayment->load(['supplier', 'branch', 'bill', 'postedBy', 'voidedBy']);
        // SUPPLIER-CREDIT-CLARITY-1: on a running account, the invoices this payment paid and the advance left from it.
        $runningAccount = PurchasingSetting::supplierRunningAccount();
        $paid = $runningAccount ? app(SupplierRunningAccountService::class)->paymentPaid($supplierPayment) : [];

        return view('tenant.supplier-payments.show', compact('supplierPayment', 'runningAccount', 'paid'));
    }

    /**
     * SUPPLIER-PAYMENT-VOID-1 — ghalat payment ulti karo (cash wapas, supplier ka payable wapas,
     * GL reversal, bill dobara khula). Wajah lazmi. Poora kaam SupplierPayableService::voidPayment().
     */
    public function void(Request $request, SupplierPayment $supplierPayment)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            // VOID-ON-ORIGINAL-DATE-1: wipe on the payment's own date, or reverse today (default).
            'void_date_mode' => \App\Support\VoidDate::rule(),
        ]);
        $mode = \App\Support\VoidDate::mode($data['void_date_mode'] ?? null);

        try {
            app(SupplierPayableService::class)->voidPayment($supplierPayment, $data['reason'], auth('tenant')->id(), $mode);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        $status = 'Payment ' . $supplierPayment->payment_no . ' voided — '
            . number_format((float) $supplierPayment->amount, 2) . ' is back in the cash/bank account and on the supplier\'s balance'
            . ($mode === \App\Support\VoidDate::ORIGINAL
                ? ', on the payment\'s own date (' . \Illuminate\Support\Carbon::parse($supplierPayment->payment_date)->format('d M Y') . ').'
                : ', dated today.');

        return redirect(\App\Support\CashBookReturn::from($request) ?? url('/supplier-payments/' . $supplierPayment->id))
            ->with('status', $status);
    }
}
