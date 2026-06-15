<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Account;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CashBankAccountTransaction;
use App\Models\Tenant\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CashBankAccountController extends Controller
{
    public function index(Request $request)
    {
        $query = CashBankAccount::query()->with(['account', 'branch']);

        if ($request->filled('type') && in_array($request->type, CashBankAccount::TYPES, true)) {
            $query->where('account_type', $request->type);
        }

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
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $accounts = $query->orderBy('account_type')->orderBy('code')->get();

        return view('tenant.finance.cash-bank-accounts.index', [
            'accounts' => $accounts,
            'types'    => CashBankAccount::TYPES,
            'filters'  => $request->only(['type', 'status', 'q']),
        ]);
    }

    public function create()
    {
        return view('tenant.finance.cash-bank-accounts.create', $this->formData());
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        if ($error = $this->assetAccountError($data['account_id'] ?? null)) {
            return back()->withInput()->withErrors(['account_id' => $error]);
        }

        $opening = (float) ($data['opening_balance'] ?? 0);

        DB::transaction(function () use ($data, $request, $opening) {
            if ($request->boolean('is_default')) {
                CashBankAccount::query()->update(['is_default' => false]);
            }

            $account = CashBankAccount::create([
                'account_id'      => $data['account_id'] ?? null,
                'branch_id'       => $data['branch_id'] ?? null,
                'currency_id'     => $data['currency_id'] ?? null,
                'code'            => $data['code'],
                'name'            => $data['name'],
                'account_type'    => $data['account_type'],
                'bank_name'       => $data['bank_name'] ?? null,
                'account_number'  => $data['account_number'] ?? null,
                'iban'            => $data['iban'] ?? null,
                'opening_balance' => $opening,
                'current_balance' => $opening,
                'is_default'      => $request->boolean('is_default'),
                'is_system'       => false,
                'is_active'       => $request->boolean('is_active'),
                'notes'           => $data['notes'] ?? null,
            ]);

            if ($opening != 0.0) {
                CashBankAccountTransaction::create([
                    'cash_bank_account_id' => $account->id,
                    'transaction_date'     => now()->toDateString(),
                    'direction'            => $opening >= 0 ? 'in' : 'out',
                    'amount'               => abs($opening),
                    'balance_after'        => $opening,
                    'transaction_type'     => 'opening_balance',
                    'notes'                => 'Opening balance',
                    'created_by_user_id'   => Auth::guard('tenant')->id(),
                ]);
            }
        });

        return redirect(url('/finance/cash-bank-accounts'))->with('status', 'Cash/Bank account created.');
    }

    public function edit(CashBankAccount $cashBankAccount)
    {
        return view('tenant.finance.cash-bank-accounts.edit', $this->formData() + [
            'cashBankAccount' => $cashBankAccount,
        ]);
    }

    public function update(Request $request, CashBankAccount $cashBankAccount)
    {
        $data = $this->validateData($request, $cashBankAccount);

        if ($error = $this->assetAccountError($data['account_id'] ?? null)) {
            return back()->withInput()->withErrors(['account_id' => $error]);
        }

        // Balances are NOT editable here (managed via opening balance on create /
        // future manual adjustments) — only descriptive + linkage fields change.
        DB::transaction(function () use ($data, $request, $cashBankAccount) {
            if ($request->boolean('is_default')) {
                CashBankAccount::query()->where('id', '!=', $cashBankAccount->id)->update(['is_default' => false]);
            }

            $cashBankAccount->update([
                'account_id'     => $data['account_id'] ?? null,
                'branch_id'      => $data['branch_id'] ?? null,
                'currency_id'    => $data['currency_id'] ?? null,
                'code'           => $data['code'],
                'name'           => $data['name'],
                'account_type'   => $data['account_type'],
                'bank_name'      => $data['bank_name'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'iban'           => $data['iban'] ?? null,
                'is_default'     => $request->boolean('is_default'),
                'is_active'      => $request->boolean('is_active'),
                'notes'          => $data['notes'] ?? null,
            ]);
        });

        return redirect(url('/finance/cash-bank-accounts'))->with('status', 'Cash/Bank account updated.');
    }

    /**
     * Destroy = deactivate (no hard delete). System accounts are protected.
     */
    public function destroy(CashBankAccount $cashBankAccount)
    {
        if ($cashBankAccount->is_system) {
            return back()->withErrors(['account' => 'System cash/bank accounts cannot be deleted.']);
        }

        $cashBankAccount->update(['is_active' => false, 'is_default' => false]);

        return back()->with('status', 'Cash/Bank account deactivated.');
    }

    private function validateData(Request $request, ?CashBankAccount $account = null): array
    {
        $rules = [
            'code'           => ['required', 'string', 'max:50', Rule::unique('cash_bank_accounts', 'code')->ignore($account?->id)],
            'name'           => ['required', 'string', 'max:255'],
            'account_type'   => ['required', Rule::in(CashBankAccount::TYPES)],
            'account_id'     => ['nullable', 'integer', 'exists:accounts,id'],
            'branch_id'      => ['nullable', 'integer', 'exists:branches,id'],
            'currency_id'    => ['nullable', 'integer', 'exists:currencies,id'],
            'bank_name'      => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:100'],
            'iban'           => ['nullable', 'string', 'max:50'],
            'notes'          => ['nullable', 'string', 'max:1000'],
        ];

        // Opening balance only settable on create.
        if (! $account) {
            $rules['opening_balance'] = ['nullable', 'numeric'];
        }

        return $request->validate($rules);
    }

    private function assetAccountError(?int $accountId): ?string
    {
        if (! $accountId) {
            return null;
        }

        $type = Account::whereKey($accountId)->value('type');

        return $type === 'asset' ? null : 'The linked Chart of Account must be an asset account.';
    }

    private function formData(): array
    {
        return [
            'coaAccounts' => Account::query()->where('type', 'asset')->where('is_active', true)
                ->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name']),
            'branches'    => Branch::query()->orderBy('name')->get(['id', 'name']),
            'currencies'  => Currency::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ];
    }
}
