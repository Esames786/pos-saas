<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Account;
use App\Models\Tenant\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = ExpenseCategory::query()->with('account');

        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        if ($request->filled('q')) {
            $search = trim($request->q);
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%");
            });
        }

        return view('tenant.finance.expense-categories.index', [
            'categories' => $query->orderBy('sort_order')->orderBy('code')->get(),
            'filters'    => $request->only(['status', 'q']),
        ]);
    }

    public function create()
    {
        return view('tenant.finance.expense-categories.create', ['expenseAccounts' => $this->expenseAccounts()]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        if ($error = $this->expenseAccountError($data['account_id'] ?? null)) {
            return back()->withInput()->withErrors(['account_id' => $error]);
        }

        ExpenseCategory::create($data + ['is_system' => false]);

        return redirect(url('/finance/expense-categories'))->with('status', 'Expense category created.');
    }

    public function edit(ExpenseCategory $expenseCategory)
    {
        return view('tenant.finance.expense-categories.edit', [
            'expenseCategory' => $expenseCategory,
            'expenseAccounts' => $this->expenseAccounts(),
        ]);
    }

    public function update(Request $request, ExpenseCategory $expenseCategory)
    {
        $data = $this->validateData($request, $expenseCategory);

        if ($error = $this->expenseAccountError($data['account_id'] ?? null)) {
            return back()->withInput()->withErrors(['account_id' => $error]);
        }

        $expenseCategory->update($data);

        return redirect(url('/finance/expense-categories'))->with('status', 'Expense category updated.');
    }

    /** Destroy = deactivate (no hard delete). System categories are protected. */
    public function destroy(ExpenseCategory $expenseCategory)
    {
        if ($expenseCategory->is_system) {
            return back()->withErrors(['category' => 'System expense categories cannot be deleted.']);
        }

        $expenseCategory->update(['is_active' => false]);

        return back()->with('status', 'Expense category deactivated.');
    }

    private function validateData(Request $request, ?ExpenseCategory $category = null): array
    {
        $data = $request->validate([
            'code'        => ['required', 'string', 'max:50', Rule::unique('expense_categories', 'code')->ignore($category?->id)],
            'name'        => ['required', 'string', 'max:255'],
            'account_id'  => ['nullable', 'integer', 'exists:accounts,id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
        ]);

        $data['is_active']  = $request->boolean('is_active');
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
    }

    private function expenseAccountError(?int $accountId): ?string
    {
        if (! $accountId) {
            return null;
        }

        $type = Account::whereKey($accountId)->value('type');

        return $type === 'expense' ? null : 'The linked Chart of Account must be an expense account.';
    }

    private function expenseAccounts()
    {
        return Account::query()->where('type', 'expense')->where('is_active', true)
            ->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name']);
    }
}
