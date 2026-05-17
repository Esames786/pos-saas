<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Customer;
use Illuminate\Http\Request;
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

    public function quickStore(Request $request)
    {
        $data = $request->validate([
            'name'  => ['required', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:190'],
        ]);

        $customer = Customer::create([
            'code'   => null,
            'name'   => $data['name'],
            'phone'  => $data['phone'] ?? null,
            'email'  => $data['email'] ?? null,
            'status' => 'active',
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'customer' => $customer]);
        }

        return redirect(url('/pos?customer_id=' . $customer->id))
            ->with('status', 'Customer created successfully.');
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
