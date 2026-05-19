<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentMethodController extends Controller
{
    public function index()
    {
        return view('tenant.payment-methods.index', [
            'methods' => PaymentMethod::orderBy('method_type')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        PaymentMethod::create($this->validateMethod($request));

        return back()->with('status', 'Payment method created successfully.');
    }

    public function update(Request $request, PaymentMethod $paymentMethod)
    {
        $paymentMethod->update($this->validateMethod($request, $paymentMethod));

        return back()->with('status', 'Payment method updated successfully.');
    }

    public function destroy(PaymentMethod $paymentMethod)
    {
        if ($paymentMethod->payments()->exists()) {
            return back()->withErrors([
                'payment_method' => 'Payment method has transactions and cannot be deleted.',
            ]);
        }

        $paymentMethod->delete();

        return back()->with('status', 'Payment method deleted successfully.');
    }

    private function validateMethod(Request $request, ?PaymentMethod $paymentMethod = null): array
    {
        $data = $request->validate([
            'code'               => ['required', 'string', 'max:50', Rule::unique('payment_methods', 'code')->ignore($paymentMethod?->id)],
            'name'               => ['required', 'string', 'max:190'],
            'method_type'        => ['required', Rule::in(['cash', 'card', 'bank_transfer', 'cheque', 'wallet', 'other'])],
            'requires_reference' => ['nullable', 'boolean'],
            'is_cash_drawer'     => ['nullable', 'boolean'],
            'is_active'          => ['nullable', 'boolean'],
        ]);

        return [
            'code'               => strtoupper(trim($data['code'])),
            'name'               => $data['name'],
            'method_type'        => $data['method_type'],
            'requires_reference' => !empty($data['requires_reference']),
            'is_cash_drawer'     => !empty($data['is_cash_drawer']),
            'is_active'          => !empty($data['is_active']),
        ];
    }
}
