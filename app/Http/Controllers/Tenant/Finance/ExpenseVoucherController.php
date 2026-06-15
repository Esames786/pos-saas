<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CashBankAccountTransaction;
use App\Models\Tenant\ExpenseCategory;
use App\Models\Tenant\ExpenseVoucher;
use App\Services\Finance\ExpenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class ExpenseVoucherController extends Controller
{
    public function __construct(private ExpenseService $expenses) {}

    public function index(Request $request)
    {
        $query = ExpenseVoucher::query()->with(['branch', 'cashBankAccount']);

        if ($request->filled('status') && in_array($request->status, ExpenseVoucher::STATUSES, true)) {
            $query->where('status', $request->status);
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', (int) $request->branch_id);
        }

        if ($request->filled('q')) {
            $search = trim($request->q);
            $query->where(function ($q) use ($search) {
                $q->where('voucher_no', 'like', "%{$search}%")->orWhere('payee_name', 'like', "%{$search}%");
            });
        }

        return view('tenant.finance.expenses.index', [
            'vouchers' => $query->orderByDesc('expense_date')->orderByDesc('id')->limit(500)->get(),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'statuses' => ExpenseVoucher::STATUSES,
            'filters'  => $request->only(['status', 'branch_id', 'q']),
        ]);
    }

    public function create()
    {
        return view('tenant.finance.expenses.create', $this->formData());
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        $voucher = DB::transaction(function () use ($data, $request) {
            $voucher = ExpenseVoucher::create([
                'voucher_no'           => $data['voucher_no'] ?: $this->nextVoucherNo($data['expense_date']),
                'branch_id'            => $data['branch_id'],
                'cash_bank_account_id' => $data['cash_bank_account_id'],
                'expense_date'         => $data['expense_date'],
                'payment_date'         => $data['payment_date'] ?? null,
                'payee_name'           => $data['payee_name'] ?? null,
                'status'               => 'draft',
                'notes'                => $data['notes'] ?? null,
                'created_by_user_id'   => Auth::guard('tenant')->id(),
            ]);

            $this->syncLines($voucher, $data['lines']);
            $this->expenses->recalcTotals($voucher);

            return $voucher;
        });

        return redirect(url('/finance/expenses/' . $voucher->id))->with('status', 'Expense voucher created (draft).');
    }

    public function show(ExpenseVoucher $expenseVoucher)
    {
        $expenseVoucher->load(['branch', 'cashBankAccount', 'lines.category', 'createdBy', 'postedBy', 'voidedBy']);

        $transactions = CashBankAccountTransaction::query()
            ->where('reference_type', 'expense_voucher')
            ->where('reference_id', $expenseVoucher->id)
            ->orderBy('id')
            ->get();

        return view('tenant.finance.expenses.show', compact('expenseVoucher', 'transactions'));
    }

    public function edit(ExpenseVoucher $expenseVoucher)
    {
        if (! $expenseVoucher->isDraft()) {
            return redirect(url('/finance/expenses/' . $expenseVoucher->id))
                ->withErrors(['voucher' => 'Only draft vouchers can be edited.']);
        }

        $expenseVoucher->load('lines');

        return view('tenant.finance.expenses.edit', $this->formData() + ['expenseVoucher' => $expenseVoucher]);
    }

    public function update(Request $request, ExpenseVoucher $expenseVoucher)
    {
        if (! $expenseVoucher->isDraft()) {
            return back()->withErrors(['voucher' => 'Only draft vouchers can be edited.']);
        }

        $data = $this->validateData($request, $expenseVoucher);

        DB::transaction(function () use ($data, $expenseVoucher) {
            $expenseVoucher->update([
                'voucher_no'           => $data['voucher_no'] ?: $expenseVoucher->voucher_no,
                'branch_id'            => $data['branch_id'],
                'cash_bank_account_id' => $data['cash_bank_account_id'],
                'expense_date'         => $data['expense_date'],
                'payment_date'         => $data['payment_date'] ?? null,
                'payee_name'           => $data['payee_name'] ?? null,
                'notes'                => $data['notes'] ?? null,
            ]);

            $this->syncLines($expenseVoucher, $data['lines']);
            $this->expenses->recalcTotals($expenseVoucher);
        });

        return redirect(url('/finance/expenses/' . $expenseVoucher->id))->with('status', 'Expense voucher updated.');
    }

    public function destroy(ExpenseVoucher $expenseVoucher)
    {
        if (! $expenseVoucher->isDraft()) {
            return back()->withErrors(['voucher' => 'Only draft vouchers can be deleted. Use Void for posted vouchers.']);
        }

        $expenseVoucher->delete();

        return redirect(url('/finance/expenses'))->with('status', 'Draft expense voucher deleted.');
    }

    public function post(ExpenseVoucher $expenseVoucher)
    {
        try {
            $this->expenses->post($expenseVoucher, Auth::guard('tenant')->id());
        } catch (Throwable $e) {
            return back()->withErrors(['voucher' => $e->getMessage()]);
        }

        return redirect(url('/finance/expenses/' . $expenseVoucher->id))->with('status', 'Expense voucher posted — cash/bank balance updated.');
    }

    public function void(Request $request, ExpenseVoucher $expenseVoucher)
    {
        $data = $request->validate([
            'void_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->expenses->void($expenseVoucher, Auth::guard('tenant')->id(), $data['void_reason'] ?? null);
        } catch (Throwable $e) {
            return back()->withErrors(['voucher' => $e->getMessage()]);
        }

        return redirect(url('/finance/expenses/' . $expenseVoucher->id))->with('status', 'Expense voucher voided — cash/bank balance restored.');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function validateData(Request $request, ?ExpenseVoucher $voucher = null): array
    {
        return $request->validate([
            'voucher_no'           => ['nullable', 'string', 'max:50', Rule::unique('expense_vouchers', 'voucher_no')->ignore($voucher?->id)],
            'branch_id'            => ['required', 'integer', 'exists:branches,id'],
            'cash_bank_account_id' => ['required', 'integer', Rule::exists('cash_bank_accounts', 'id')->where('is_active', true)],
            'expense_date'         => ['required', 'date'],
            'payment_date'         => ['nullable', 'date'],
            'payee_name'           => ['nullable', 'string', 'max:255'],
            'notes'                => ['nullable', 'string', 'max:1000'],
            'lines'                => ['required', 'array', 'min:1'],
            'lines.*.expense_category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')->where('is_active', true)],
            'lines.*.description'  => ['nullable', 'string', 'max:255'],
            'lines.*.amount'       => ['required', 'numeric', 'min:0.01'],
            'lines.*.tax_amount'   => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    private function syncLines(ExpenseVoucher $voucher, array $lines): void
    {
        $voucher->lines()->delete();

        $categoryAccounts = ExpenseCategory::whereIn('id', collect($lines)->pluck('expense_category_id'))
            ->pluck('account_id', 'id');

        $sort = 0;

        foreach ($lines as $line) {
            $amount = (float) $line['amount'];
            $tax    = (float) ($line['tax_amount'] ?? 0);

            $voucher->lines()->create([
                'expense_category_id' => $line['expense_category_id'],
                'account_id'          => $categoryAccounts[$line['expense_category_id']] ?? null,
                'description'         => $line['description'] ?? null,
                'amount'              => $amount,
                'tax_amount'          => $tax,
                'line_total'          => $amount + $tax,
                'sort_order'          => $sort++,
            ]);
        }
    }

    private function nextVoucherNo(string $expenseDate): string
    {
        $prefix = 'EXP-' . Carbon::parse($expenseDate)->format('Ymd') . '-';

        $last = ExpenseVoucher::where('voucher_no', 'like', $prefix . '%')
            ->orderByDesc('voucher_no')
            ->value('voucher_no');

        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private function formData(): array
    {
        return [
            'branches'        => Branch::orderBy('name')->get(['id', 'name']),
            'cashBankAccounts' => CashBankAccount::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'account_type']),
            'categories'      => ExpenseCategory::where('is_active', true)->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name']),
        ];
    }
}
