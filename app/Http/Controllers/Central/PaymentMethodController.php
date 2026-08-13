<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Master\BillingPaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CLOUD-BILLING-1A — central admin CRUD for the manual payment-method directory.
 * Central-admin only (gated by route.permission on the central guard). Tenants never reach
 * these routes; they only read active methods on their own billing page.
 */
class PaymentMethodController extends Controller
{
    public function index()
    {
        $methods = BillingPaymentMethod::orderBy('sort_order')->orderBy('display_name')->get();

        return view('central.payment-methods.index', ['methods' => $methods]);
    }

    public function create()
    {
        return view('central.payment-methods.form', ['method' => new BillingPaymentMethod]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        BillingPaymentMethod::create($data);

        return redirect()->route('central.payment-methods.index')
            ->with('status', 'Payment method created.');
    }

    public function edit(BillingPaymentMethod $paymentMethod)
    {
        return view('central.payment-methods.form', ['method' => $paymentMethod]);
    }

    public function update(Request $request, BillingPaymentMethod $paymentMethod)
    {
        $data = $this->validated($request, $paymentMethod->id);

        $paymentMethod->update($data);

        return redirect()->route('central.payment-methods.index')
            ->with('status', 'Payment method updated.');
    }

    public function toggle(BillingPaymentMethod $paymentMethod)
    {
        $paymentMethod->update(['is_active' => ! $paymentMethod->is_active]);

        return back()->with('status', 'Payment method '.($paymentMethod->is_active ? 'activated.' : 'deactivated.'));
    }

    public function destroy(BillingPaymentMethod $paymentMethod)
    {
        $paymentMethod->delete();

        return redirect()->route('central.payment-methods.index')
            ->with('status', 'Payment method deleted.');
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'code' => [
                'required', 'string', 'max:50', 'regex:/^[a-z0-9_\-]+$/',
                Rule::unique('billing_payment_methods', 'code')
                    ->where(fn ($q) => $q->where('code', $request->input('code')))
                    ->ignore($ignoreId),
            ],
            'display_name' => ['required', 'string', 'max:100'],
            'account_title' => ['nullable', 'string', 'max:150'],
            'account_number' => ['nullable', 'string', 'max:100'],
            'instructions' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]) + ['is_active' => $request->boolean('is_active'), 'sort_order' => (int) $request->input('sort_order', 0)];
    }
}
