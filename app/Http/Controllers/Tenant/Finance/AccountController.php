<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Account;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        $hasFilter = $request->filled('type') || $request->filled('status') || $request->filled('q');

        $query = Account::query()->with('parent');

        if ($request->filled('type') && in_array($request->type, Account::TYPES, true)) {
            $query->where('type', $request->type);
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

        $allAccounts = $query->orderBy('sort_order')->orderBy('code')->get();

        // When no filter is active, build the level-wise tree.
        $tree = $hasFilter ? [] : $this->buildTree($allAccounts);

        return view('tenant.finance.accounts.index', [
            'accounts'  => $allAccounts,
            'tree'      => $tree,
            'hasFilter' => $hasFilter,
            'types'     => Account::TYPES,
            'filters'   => $request->only(['type', 'status', 'q']),
        ]);
    }

    private function buildTree($accounts, ?int $parentId = null, int $level = 0): array
    {
        $result = [];
        foreach ($accounts as $account) {
            if ((int) ($account->parent_id ?? 0) !== (int) $parentId) {
                continue;
            }
            $account->_level = $level;
            $result[] = $account;
            foreach ($this->buildTree($accounts, $account->id, $level + 1) as $child) {
                $result[] = $child;
            }
        }
        return $result;
    }

    public function create()
    {
        return view('tenant.finance.accounts.create', [
            'parents' => $this->parentOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        Account::create($data + ['is_system' => false]);

        return redirect(url('/finance/accounts'))->with('status', 'Account created.');
    }

    public function edit(Account $account)
    {
        return view('tenant.finance.accounts.edit', [
            'account' => $account,
            'parents' => $this->parentOptions($account->id),
        ]);
    }

    public function update(Request $request, Account $account)
    {
        $data = $this->validateData($request, $account);

        if ((int) ($data['parent_id'] ?? 0) === $account->id) {
            return back()->withInput()->withErrors(['parent_id' => 'An account cannot be its own parent.']);
        }

        $account->update($data);

        return redirect(url('/finance/accounts'))->with('status', 'Account updated.');
    }

    /**
     * Destroy = deactivate (no hard delete while journals don't exist yet).
     * System accounts and accounts with children are protected.
     */
    public function destroy(Account $account)
    {
        if ($account->is_system) {
            return back()->withErrors(['account' => 'System accounts cannot be deleted.']);
        }

        if ($account->children()->exists()) {
            return back()->withErrors(['account' => 'This account has sub-accounts. Reassign or remove them first.']);
        }

        $account->update(['is_active' => false]);

        return back()->with('status', 'Account deactivated.');
    }

    private function validateData(Request $request, ?Account $account = null): array
    {
        $data = $request->validate([
            'code'           => ['required', 'string', 'max:50', Rule::unique('accounts', 'code')->ignore($account?->id)],
            'name'           => ['required', 'string', 'max:255'],
            'type'           => ['required', Rule::in(Account::TYPES)],
            'normal_balance' => ['required', Rule::in(Account::NORMAL_BALANCES)],
            'parent_id'      => ['nullable', 'integer', 'exists:accounts,id'],
            'description'    => ['nullable', 'string', 'max:1000'],
            'sort_order'     => ['nullable', 'integer', 'min:0'],
        ]);

        $data['is_active']  = $request->boolean('is_active');
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
    }

    private function parentOptions(?int $excludeId = null)
    {
        return Account::query()
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->orderBy('sort_order')->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);
    }
}
