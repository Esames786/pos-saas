<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Master\BillingPaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        $this->assertActivatableIfActive($data);

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
        $this->assertActivatableIfActive($data);

        $paymentMethod->update($data);

        return redirect()->route('central.payment-methods.index')
            ->with('status', 'Payment method updated.');
    }

    public function toggle(BillingPaymentMethod $paymentMethod)
    {
        // Activating requires complete customer-facing config (account title + number);
        // deactivating is always allowed. A method must never be "active but unpayable".
        if (! $paymentMethod->is_active && ! $paymentMethod->isConfigured()) {
            return back()->withErrors([
                'is_active' => 'Add the account title and account number before activating this method.',
            ]);
        }

        $paymentMethod->update(['is_active' => ! $paymentMethod->is_active]);

        return back()->with('status', 'Payment method '.($paymentMethod->is_active ? 'activated.' : 'deactivated.'));
    }

    public function destroy(BillingPaymentMethod $paymentMethod)
    {
        // Never orphan historical payment records: subscription_payments.payment_method_code is a
        // soft string reference (no FK), so once any payment has cited this code a hard delete
        // would make that history uninterpretable. Deactivate instead — hard delete is allowed
        // only for a method that was never referenced.
        if ($paymentMethod->hasBeenReferenced()) {
            $paymentMethod->update(['is_active' => false]);

            return redirect()->route('central.payment-methods.index')
                ->with('status', 'This method has payment history, so it was deactivated instead of deleted.');
        }

        $paymentMethod->delete();

        return redirect()->route('central.payment-methods.index')
            ->with('status', 'Payment method deleted.');
    }

    /**
     * A method may only be stored/updated in the ACTIVE state when it carries the customer-facing
     * config a tenant needs to actually pay. This is the authoritative server-side guard — it does
     * NOT rely on the tenant side merely hiding incomplete methods.
     */
    private function assertActivatableIfActive(array $data): void
    {
        $wantsActive = filter_var($data['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($wantsActive
            && ! (filled($data['account_title'] ?? null) && filled($data['account_number'] ?? null))) {
            throw ValidationException::withMessages([
                'is_active' => 'Fill the account title and account number before activating this method.',
            ]);
        }
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
