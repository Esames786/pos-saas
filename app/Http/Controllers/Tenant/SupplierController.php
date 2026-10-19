<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierLedger;
use App\Support\ScreenAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

            // Mirror the opening payable into the GL (Dr Opening Balance Equity / Cr Accounts
            // Payable) so the supplier ledger reconciles with its control account and the
            // Trial Balance is right. Idempotent + safe (never breaks supplier creation).
            app(\App\Services\Finance\JournalPostingService::class)
                ->postSupplierOpeningBalance($supplier, auth('tenant')->id());
        }

        return redirect(url('/suppliers'))->with('status', 'Supplier created.');
    }

    /**
     * PURCHASING-V2-2 — "+ New supplier" on the GRN / PO screen: only a name (and a phone), the code made by
     * itself, saved without leaving the page. Whoever can open a purchase screen may add one (owner's rule),
     * so this gates on those screens, not on the Suppliers page.
     */
    public function quickStore(Request $request)
    {
        ScreenAccess::purchasing(['tenant.goods-receipts.create', 'tenant.purchase-orders.create', 'tenant.purchase-returns.create', 'tenant.suppliers.create']);

        $data = $request->validate([
            'name'  => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
        ], ['name.required' => "Write the supplier's name."]);

        $name = trim(preg_replace('/\s+/u', ' ', $data['name']));
        $same = Supplier::withTrashed()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        if ($same) {
            throw ValidationException::withMessages(['name' => '"' . $same->name . '" is already a supplier' . ($same->trashed() || $same->status !== 'active' ? ' (switched off) — turn it on in Suppliers.' : ' — pick it from the list.')]);
        }

        $supplier = Supplier::create([
            'code'               => self::nextCode(),
            'name'               => $name,
            'phone'              => filled($data['phone'] ?? null) ? trim($data['phone']) : null,
            'payment_terms_days' => 0,
            'opening_balance'    => 0,
            'current_balance'    => 0,
            'status'             => 'active',
        ]);

        return response()->json([
            'id' => $supplier->id, 'name' => $supplier->name, 'code' => $supplier->code, 'phone' => $supplier->phone,
            'balance' => 0.0, 'terms' => 0,
        ], 201);
    }

    /** SUP-0001, SUP-0002 … — the next number after every code that already looks like that. */
    private static function nextCode(): string
    {
        $n = (int) Supplier::withTrashed()->where('code', 'like', 'SUP-%')->pluck('code')
            ->map(fn ($c) => (int) preg_replace('/\D/', '', substr((string) $c, 4)))->max();
        do {
            $code = 'SUP-' . str_pad((string) ++$n, 4, '0', STR_PAD_LEFT);
        } while (Supplier::withTrashed()->where('code', $code)->exists());

        return $code;
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
