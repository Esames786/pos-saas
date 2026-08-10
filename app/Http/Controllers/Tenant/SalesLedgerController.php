<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\SalesLedger;
use Illuminate\Http\Request;

class SalesLedgerController extends Controller
{
    public function index(Request $request)
    {
        $query = SalesLedger::with(['branch', 'order', 'createdBy'])
            ->orderByDesc('created_at');

        // USER-DATA-SCOPE-1: ledger rows follow their sale's scope (rows with no sale stay visible
        // only to unscoped users, so a counter operator never sees another terminal's money).
        $scope = app(\App\Services\Security\UserDataScope::class);
        $user = auth('tenant')->user();
        if ($scope->isScoped($user)) {
            $query->whereHas('order', fn ($q) => $scope->applyToSales($q, $user));
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('entry_type')) {
            $query->where('entry_type', $request->entry_type);
        }

        return view('tenant.sales-ledger.index', [
            'ledgers'  => $query->paginate(20)->withQueryString(),
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(),
        ]);
    }
}
