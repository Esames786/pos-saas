<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\Customer;
use App\Models\Tenant\CustomerPayment;
use App\Models\Tenant\SalesOrder;
use App\Services\Finance\CustomerReceivableService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CustomerPaymentController extends Controller
{
    public function __construct(private CustomerReceivableService $receivables) {}

    public function index(Request $request)
    {
        $query = CustomerPayment::query()->with(['customer', 'branch', 'cashBankAccount']);

        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->customer_id);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', (int) $request->branch_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('payment_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('payment_date', '<=', $request->date_to);
        }
        if ($request->filled('q')) {
            $search = trim($request->q);
            $query->where(function ($q) use ($search) {
                $q->where('payment_no', 'like', "%{$search}%")
                  ->orWhere('reference_no', 'like', "%{$search}%")
                  ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        return view('tenant.finance.customer-payments.index', [
            'payments'  => $query->orderByDesc('payment_date')->orderByDesc('id')->limit(500)->get(),
            'customers' => Customer::where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'branches'  => Branch::orderBy('name')->get(['id', 'name']),
            'filters'   => $request->only(['customer_id', 'branch_id', 'date_from', 'date_to', 'q']),
        ]);
    }

    public function create(Request $request)
    {
        $selectedCustomer = $request->filled('customer_id') ? (int) $request->customer_id : null;

        return view('tenant.finance.customer-payments.create', $this->formData() + [
            'selectedCustomer' => $selectedCustomer,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id'          => ['required', 'integer', 'exists:tenant.customers,id'],
            'branch_id'            => ['nullable', 'integer', 'exists:tenant.branches,id'],
            'sales_order_id'       => ['nullable', 'integer', 'exists:tenant.sales_orders,id'],
            'cash_bank_account_id' => ['nullable', Rule::exists('tenant.cash_bank_accounts', 'id')->where('is_active', true)],
            'payment_date'         => ['required', 'date'],
            'amount'               => ['required', 'numeric', 'min:0.01'],
            'payment_method'       => ['nullable', 'string', 'max:50'],
            'reference_no'         => ['nullable', 'string', 'max:100'],
            'notes'                => ['nullable', 'string', 'max:1000'],
        ]);

        $payment = $this->receivables->recordPayment($data, Auth::guard('tenant')->id());

        return redirect(url('/finance/customer-payments/' . $payment->id))->with('status', 'Customer payment recorded.');
    }

    public function show(CustomerPayment $customerPayment)
    {
        $customerPayment->load(['customer', 'branch', 'salesOrder', 'cashBankAccount', 'postedBy']);

        return view('tenant.finance.customer-payments.show', compact('customerPayment'));
    }

    private function formData(): array
    {
        return [
            'customers' => Customer::where('status', 'active')->orderBy('name')->get(['id', 'name', 'current_balance']),
            'branches'  => Branch::orderBy('name')->get(['id', 'name']),
            'cashBankAccounts' => CashBankAccount::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            // Outstanding sales orders for allocation (unpaid/partial with balance).
            'openOrders' => SalesOrder::query()
                ->whereNotNull('customer_id')
                ->whereIn('payment_status', ['unpaid', 'partial'])
                ->where('balance_due', '>', 0)
                ->orderByDesc('sale_date')
                ->limit(200)
                ->get(['id', 'sale_no', 'customer_id', 'balance_due']),
        ];
    }
}
