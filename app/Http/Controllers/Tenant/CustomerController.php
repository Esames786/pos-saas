<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Customer;
use App\Models\Tenant\CustomerLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::query()->latest();

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return view('tenant.customers.index', [
            'customers' => $query->paginate(15)->withQueryString(),
        ]);
    }

    public function create()
    {
        return view('tenant.customers.form', [
            'customer' => null,
            'title'    => 'Create Customer',
        ]);
    }

    public function store(Request $request)
    {
        Customer::create($this->validateCustomer($request));

        return redirect(url('/customers'))->with('status', 'Customer created successfully.');
    }

    public function show(Customer $customer)
    {
        $customer->loadCount('salesOrders');

        return view('tenant.customers.show', compact('customer'));
    }

    public function edit(Customer $customer)
    {
        return view('tenant.customers.form', [
            'customer' => $customer,
            'title'    => 'Edit Customer',
        ]);
    }

    public function update(Request $request, Customer $customer)
    {
        $customer->update($this->validateCustomer($request, $customer));

        return redirect(url('/customers/' . $customer->id))->with('status', 'Customer updated successfully.');
    }

    public function destroy(Customer $customer)
    {
        if ($customer->salesOrders()->exists()) {
            return back()->withErrors([
                'customer' => 'Customer has sales history and cannot be deleted.',
            ]);
        }

        $customer->delete();

        return redirect(url('/customers'))->with('status', 'Customer deleted successfully.');
    }

    public function ledger(Customer $customer)
    {
        $ledgers = CustomerLedger::where('customer_id', $customer->id)
            ->with(['branch', 'createdBy'])
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(30);

        return view('tenant.customers.ledger', compact('customer', 'ledgers'));
    }

    public function quickStore(Request $request)
    {
        $data = $request->validate([
            'name'    => ['required', 'string', 'max:190'],
            'phone'   => ['nullable', 'string', 'max:50'],
            'email'   => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        // The counter creates customers mid-rush, so the same phone was being added again and
        // again (a real book had five "tabish 0333…" rows). A known phone REUSES that customer
        // and just tops up what is missing, instead of minting another duplicate.
        $phone = trim((string) ($data['phone'] ?? ''));
        [$customer, $reused] = DB::connection('tenant')->transaction(function () use ($data, $phone) {
            $customer = $phone !== '' ? $this->customerByPhone($phone, true) : null;
            $reused = (bool) $customer;

            if ($customer) {
                $customer->fill(array_filter([
                    'name' => $customer->name ?: $data['name'],
                    'email' => $customer->email ?: ($data['email'] ?? null),
                ]))->save();
            } else {
                $customer = Customer::create([
                    'code' => null,
                    'name' => $data['name'],
                    'phone' => $phone !== '' ? $this->normalizePhone($phone) : null,
                    'email' => $data['email'] ?? null,
                    'status' => 'active',
                ]);
            }

            if (! empty($data['address'])) {
                $this->storeAddressOnce($customer, $data['address']);
            }

            return [$customer, $reused];
        });

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'reused' => $reused,
                'customer' => $customer->fresh()->load('addresses'),
            ]);
        }

        return redirect(url('/pos?customer_id=' . $customer->id))
            ->with('status', 'Customer created successfully.');
    }

    /** CUSTOMER-UX-1: add an address to a customer's book from the POS modal. */
    public function storeAddress(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'label'      => ['nullable', 'string', 'max:50'],
            'address'    => ['required', 'string', 'max:500'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $address = DB::connection('tenant')->transaction(function () use ($customer, $data) {
            $makeDefault = ! empty($data['is_default']) || $customer->addresses()->count() === 0;
            if ($makeDefault) {
                $customer->addresses()->update(['is_default' => false]);
            }

            return $this->storeAddressOnce($customer, $data['address'], $data['label'] ?? null, $makeDefault);
        });

        return response()->json(['ok' => true, 'address' => $address]);
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function customerByPhone(string $phone, bool $lock = false): ?Customer
    {
        $normalized = $this->normalizePhone($phone);
        if ($normalized === '') {
            return null;
        }

        // The exact normalized lookup uses the phone index. Under InnoDB's normal repeatable-read
        // isolation, lockForUpdate also locks the missing key gap, preventing two simultaneous POS
        // requests from inserting the same newly-normalized phone.
        $exact = Customer::where('phone', $normalized);
        if ($lock) {
            $exact->lockForUpdate();
        }
        if ($customer = $exact->first()) {
            return $customer;
        }

        $query = Customer::whereNotNull('phone')->whereRaw(
            "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') = ?",
            [$normalized]
        );
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function storeAddressOnce(Customer $customer, string $address, ?string $label = null, ?bool $makeDefault = null)
    {
        $address = trim(preg_replace('/\s+/', ' ', $address) ?? $address);
        $existing = $customer->addresses()->lockForUpdate()->get()->first(
            fn ($row) => mb_strtolower(trim(preg_replace('/\s+/', ' ', $row->address) ?? $row->address)) === mb_strtolower($address)
        );
        if ($existing) {
            if ($makeDefault && ! $existing->is_default) {
                $existing->update(['is_default' => true, 'label' => $label ?: $existing->label]);
            }
            return $existing;
        }

        return $customer->addresses()->create([
            'label' => $label,
            'address' => $address,
            'is_default' => $makeDefault ?? $customer->addresses()->count() === 0,
        ]);
    }

    private function validateCustomer(Request $request, ?Customer $customer = null): array
    {
        $data = $request->validate([
            'code'          => ['nullable', 'string', 'max:50', Rule::unique('customers', 'code')->ignore($customer?->id)],
            'name'          => ['required', 'string', 'max:190'],
            'phone'         => ['nullable', 'string', 'max:50'],
            'email'         => ['nullable', 'email', 'max:190'],
            'address'       => ['nullable', 'string'],
            'tax_number'    => ['nullable', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date'],
            'gender'        => ['nullable', Rule::in(['male', 'female', 'other'])],
            'status'        => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $data['code'] = $data['code'] ? strtoupper(trim($data['code'])) : null;

        return $data;
    }
}
