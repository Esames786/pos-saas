<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierLedger;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index()
    {
        $suppliers = Supplier::orderBy('name')->paginate(20);
        return view('tenant.suppliers.index', compact('suppliers'));
    }

    public function create()
    {
        return view('tenant.suppliers.create', ['supplier' => null, 'title' => 'Create Supplier']);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code'                => 'required|string|max:50|unique:tenant.suppliers,code',
            'name'                => 'required|string|max:255',
            'contact_person'      => 'nullable|string|max:255',
            'phone'               => 'nullable|string|max:50',
            'email'               => 'nullable|email|max:200',
            'address'             => 'nullable|string|max:1000',
            'tax_number'          => 'nullable|string|max:100',
            'payment_terms_days'  => 'nullable|integer|min:0|max:365',
            'opening_balance'     => 'nullable|numeric|min:0',
            'status'              => 'required|in:active,inactive',
        ]);

        $openingBalance = (float) ($data['opening_balance'] ?? 0);

        $supplier = Supplier::create([
            ...$data,
            'opening_balance' => $openingBalance,
            'current_balance' => $openingBalance,
        ]);

        if ($openingBalance > 0) {
            SupplierLedger::create([
                'supplier_id'      => $supplier->id,
                'entry_type'       => 'opening_balance',
                'direction'        => 'debit',
                'amount'           => $openingBalance,
                'balance_after'    => $openingBalance,
                'reference_no'     => 'OPENING',
                'created_by_user_id' => auth('tenant')->id(),
            ]);
        }

        return redirect(url('/suppliers'))->with('status', 'Supplier created.');
    }

    public function show(Supplier $supplier)
    {
        return view('tenant.suppliers.show', compact('supplier'));
    }

    public function edit(Supplier $supplier)
    {
        return view('tenant.suppliers.edit', ['supplier' => $supplier, 'title' => 'Edit Supplier: ' . $supplier->name]);
    }

    public function update(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'code'               => 'required|string|max:50|unique:tenant.suppliers,code,' . $supplier->id,
            'name'               => 'required|string|max:255',
            'contact_person'     => 'nullable|string|max:255',
            'phone'              => 'nullable|string|max:50',
            'email'              => 'nullable|email|max:200',
            'address'            => 'nullable|string|max:1000',
            'tax_number'         => 'nullable|string|max:100',
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
            'status'             => 'required|in:active,inactive',
        ]);

        $supplier->update($data);

        return redirect(url('/suppliers/' . $supplier->id))->with('status', 'Supplier updated.');
    }

    public function destroy(Supplier $supplier)
    {
        if (
            $supplier->ledgers()->exists()
            || $supplier->purchaseOrders()->exists()
            || $supplier->goodsReceipts()->exists()
            || $supplier->purchaseBills()->exists()
            || $supplier->payments()->exists()
        ) {
            return back()->withErrors(['supplier' => 'Supplier has transactions and cannot be deleted.']);
        }

        $supplier->delete();
        return redirect(url('/suppliers'))->with('status', 'Supplier deleted.');
    }

    public function ledger(Supplier $supplier)
    {
        $ledgers = SupplierLedger::where('supplier_id', $supplier->id)
            ->with('createdBy')
            ->orderByDesc('created_at')
            ->paginate(30);

        return view('tenant.suppliers.ledger', compact('supplier', 'ledgers'));
    }
}
