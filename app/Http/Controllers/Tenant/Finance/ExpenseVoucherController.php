<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Account;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CashBankAccountTransaction;
use App\Models\Tenant\ExpenseCategory;
use App\Models\Tenant\ExpenseVoucher;
use App\Models\Tenant\ExpenseVoucherLine;
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
        // EXPENSE-LIST-CATEGORY-FILTER-1: "how much went to 6810-2 Supervisor Fee from 1 to 10 Oct?"
        // had no screen that could answer it. Dates, Category (the account) and Sub-category (the
        // expense category) now narrow the list, and a total under it answers the question.
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $categories = ExpenseCategory::query()->with('account:id,code,name')
            ->orderBy('code')->orderBy('name')->get(['id', 'account_id', 'code', 'name', 'is_active']);

        // Category = the account the LINE was posted to, not the one its category points at today:
        // syncLines() copies it on save, and if a category is later re-linked, old vouchers must
        // still show — and filter by — the account their journal actually hit. So the dropdown
        // offers every account any line or any category uses.
        $accountIds = ExpenseVoucherLine::query()->whereNotNull('account_id')->distinct()->pluck('account_id')
            ->merge($categories->pluck('account_id'))->filter()->unique()->values();
        $accounts = Account::whereIn('id', $accountIds)->orderBy('code')->get(['id', 'code', 'name']);

        // An id nobody has (a stale bookmark, a hand-typed URL) is ignored, not an error.
        $accountId  = $accounts->contains('id', (int) $request->input('account_id')) ? (int) $request->input('account_id') : null;
        $categoryId = $categories->contains('id', (int) $request->input('expense_category_id')) ? (int) $request->input('expense_category_id') : null;
        $byCategory = $accountId !== null || $categoryId !== null;

        $lineFilter = function ($q) use ($accountId, $categoryId) {
            if ($accountId !== null) {
                $q->where('account_id', $accountId);
            }
            if ($categoryId !== null) {
                $q->where('expense_category_id', $categoryId);
            }
        };

        $query = ExpenseVoucher::query();
        $this->applyListFilters($query, $request);
        if ($byCategory) {
            $query->whereHas('lines', $lineFilter);
        }

        // Counts per status straight from the database — what the filters match, not what the
        // page happens to show.
        $statusCounts = (clone $query)->reorder()->selectRaw('status, COUNT(*) as n')->groupBy('status')
            ->pluck('n', 'status')->map(fn ($n) => (int) $n);

        // The TOTAL is posted money only: a void was reversed and a draft was never paid. It is
        // summed by the database over every matching line, never from the rendered rows — the list
        // stops at 500 vouchers and a page total would silently drop the rest. line_total (tax
        // included) is what posting debits to the line's account, so it agrees with the ledger.
        $totalQuery = ExpenseVoucherLine::query()
            ->whereHas('voucher', function ($v) use ($request) {
                $this->applyListFilters($v, $request);
                $v->where('status', 'posted');
            });
        $lineFilter($totalQuery);
        $postedTotal = (float) $totalQuery->sum('line_total');

        $vouchers = $query
            ->with(['branch', 'cashBankAccount', 'lines.category:id,code,name', 'lines.account:id,code,name'])
            // A voucher can hold several categories (EXP-20261002-0006 = 500 stationery + 11,920
            // marketing). Filtered by one, the row shows that category's share beside the voucher
            // total, so nobody thinks the voucher shrank.
            ->withSum(['lines as matched_amount' => $lineFilter], 'line_total')
            ->orderByDesc('expense_date')->orderByDesc('id')->limit(500)->get();

        return view('tenant.finance.expenses.index', [
            'vouchers'     => $vouchers,
            'branches'     => Branch::orderBy('name')->get(['id', 'name']),
            'statuses'     => ExpenseVoucher::STATUSES,
            'accounts'     => $accounts,
            'categories'   => $categories,
            'byCategory'   => $byCategory,
            'statusCounts' => $statusCounts,
            'postedTotal'  => $postedTotal,
            'filters'      => $request->only(['status', 'branch_id', 'q', 'date_from', 'date_to'])
                + ['account_id' => $accountId, 'expense_category_id' => $categoryId],
        ]);
    }

    /**
     * Status, Branch, Search and the dates — shared by the list, its counts and its total, so the
     * three can never disagree about which vouchers the filters match.
     */
    private function applyListFilters($query, Request $request): void
    {
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

        // expense_date — the Date column the list shows. payment_date is not a filter.
        if ($request->filled('date_from')) {
            $query->whereDate('expense_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('expense_date', '<=', $request->input('date_to'));
        }
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
