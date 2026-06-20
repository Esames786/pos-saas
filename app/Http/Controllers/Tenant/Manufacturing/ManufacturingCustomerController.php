<?php

namespace App\Http\Controllers\Tenant\Manufacturing;

use App\Http\Controllers\Concerns\GeneratesSequentialCode;
use App\Http\Controllers\Controller;
use App\Models\Tenant\ManufacturingCustomer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ManufacturingCustomerController extends Controller
{
    use GeneratesSequentialCode;

    public function index(Request $request)
    {
        $query = ManufacturingCustomer::query();

        if ($request->filled('q')) {
            $s = trim($request->q);
            $query->where(function ($q) use ($s) {
                $q->where('code', 'like', "%{$s}%")
                  ->orWhere('name', 'like', "%{$s}%")
                  ->orWhere('company_name', 'like', "%{$s}%")
                  ->orWhere('contact_person', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%")
                  ->orWhere('mobile', 'like', "%{$s}%");
            });
        }

        if ($request->filled('status') && in_array($request->status, ['active', 'inactive'], true)) {
            $query->where('status', $request->status);
        }

        $customers = $query->orderBy('name')->paginate(20)->withQueryString();

        return view('tenant.manufacturing.customers.index', [
            'customers' => $customers,
            'filters'   => $request->only(['q', 'status']),
        ]);
    }

    public function create()
    {
        return view('tenant.manufacturing.customers.create', [
            'customer' => null,
            'title'    => 'Add Manufacturing Customer',
            'nextCode' => $this->nextCode(),
        ]);
    }

    public function store(Request $request)
    {
        if (empty(trim($request->input('code', '')))) {
            $request->merge(['code' => $this->nextCode()]);
        }

        $data = $this->validate($request, $this->rules());
        $data['created_by_user_id'] = auth('tenant')->id();

        $customer = ManufacturingCustomer::create($data);

        return redirect(url('/manufacturing/customers/' . $customer->id))
            ->with('status', 'Manufacturing customer created.');
    }

    public function show(ManufacturingCustomer $manufacturingCustomer)
    {
        return view('tenant.manufacturing.customers.show', [
            'customer' => $manufacturingCustomer,
        ]);
    }

    public function edit(ManufacturingCustomer $manufacturingCustomer)
    {
        return view('tenant.manufacturing.customers.edit', [
            'customer' => $manufacturingCustomer,
            'title'    => 'Edit: ' . $manufacturingCustomer->name,
            'nextCode' => null,
        ]);
    }

    public function update(Request $request, ManufacturingCustomer $manufacturingCustomer)
    {
        $data = $this->validate($request, $this->rules($manufacturingCustomer));
        $manufacturingCustomer->update($data);

        return redirect(url('/manufacturing/customers/' . $manufacturingCustomer->id))
            ->with('status', 'Manufacturing customer updated.');
    }

    public function destroy(ManufacturingCustomer $manufacturingCustomer)
    {
        $manufacturingCustomer->update(['status' => 'inactive']);

        return redirect(url('/manufacturing/customers'))
            ->with('status', 'Manufacturing customer deactivated.');
    }

    private function rules(?ManufacturingCustomer $customer = null): array
    {
        return [
            'code'           => ['required', 'string', 'max:50',
                                 Rule::unique('manufacturing_customers', 'code')->ignore($customer?->id)],
            'name'           => ['required', 'string', 'max:255'],
            'company_name'   => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'email'          => ['nullable', 'email', 'max:200'],
            'phone'          => ['nullable', 'string', 'max:50'],
            'mobile'         => ['nullable', 'string', 'max:50'],
            'tax_number'     => ['nullable', 'string', 'max:100'],
            'address'        => ['nullable', 'string', 'max:2000'],
            'city'           => ['nullable', 'string', 'max:100'],
            'country'        => ['nullable', 'string', 'max:100'],
            'status'         => ['required', Rule::in(['active', 'inactive'])],
            'notes'          => ['nullable', 'string', 'max:3000'],
        ];
    }

    private function nextCode(): string
    {
        return $this->nextSequentialCode(ManufacturingCustomer::class, 'code', 'MFG-CUST-', 4);
    }
}
