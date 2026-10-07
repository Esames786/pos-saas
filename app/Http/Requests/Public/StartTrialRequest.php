<?php

namespace App\Http\Requests\Public;

use App\Models\Master\TenantDomain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StartTrialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'tenant_code' => Str::of((string) $this->input('tenant_code'))
                ->lower()
                ->trim()
                ->replaceMatches('/\s+/', '-')
                ->toString(),
            'owner_email' => Str::of((string) $this->input('owner_email'))->lower()->trim()->toString(),
            // CLOUD-BILLING-2: anything but an explicit "yearly" is monthly. Only the cycle is taken from the
            // client; the amount is always recomputed from the plan (BillingPeriodResolver).
            'billing_period' => strtolower(trim((string) $this->input('billing_period'))) === 'yearly' ? 'yearly' : 'monthly',
            // WEBSITE-I18N-GEO-1 P3: the market and the counts chosen in the plan builder. Counts only —
            // SelfSignupService prices them again from the plan.
            'market' => $this->filled('market') ? strtolower(trim((string) $this->input('market'))) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:255'],
            'tenant_code' => [
                'required',
                'string',
                'max:50',
                'alpha_dash',
                Rule::notIn(config('saas.reserved_subdomains', [])),
                Rule::unique('master.tenants', 'tenant_code'),
                function ($attribute, $value, $fail) {
                    $domain = $value . '.' . config('tenancy.tenant_base_domain');

                    if (TenantDomain::where('domain', $domain)->exists()) {
                        $fail(__('This subdomain is already taken.'));
                    }
                },
            ],
            'owner_name'  => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_phone' => ['nullable', 'string', 'max:50'],
            'password'    => ['required', 'string', 'min:8', 'confirmed'],
            'plan_id' => [
                'required',
                'integer',
                Rule::exists('master.plans', 'id')->where(function ($query) {
                    $query->where('is_active', true)
                        ->where('is_public', true)
                        ->where('is_custom', false);
                }),
            ],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'billing_period' => ['required', 'in:monthly,yearly'],
            'market' => ['nullable', Rule::in(array_keys((array) config('saas.markets', [])))],
            'branches' => ['nullable', 'integer', 'min:1', 'max:' . (int) config('saas.max_self_service_branches', 10)],
            'extra_terminals' => ['nullable', 'integer', 'min:0', 'max:99'],
            // The visitor's own clock (browser Intl), used only where the market has no single timezone (US).
            'timezone' => ['nullable', 'string', Rule::in(timezone_identifiers_list())],
            // Honeypot: real users never see/fill this; bots usually do.
            'website' => ['nullable', 'size:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'tenant_code.not_in' => __('This subdomain is reserved. Please choose another.'),
            'tenant_code.unique' => __('This subdomain is already taken.'),
            'plan_id.exists'     => __('Please choose an available self-service plan.'),
            'website.size'       => __('Signup could not be completed.'),
        ];
    }

    public function signupData(): array
    {
        return $this->validated();
    }
}
