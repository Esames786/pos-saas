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
