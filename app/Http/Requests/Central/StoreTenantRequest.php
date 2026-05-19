<?php

namespace App\Http\Requests\Central;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('central')->check();
    }

    public function rules(): array
    {
        return [
            'tenant_code'   => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('tenants', 'tenant_code')],
            'business_name' => ['required', 'string', 'max:190'],
            'owner_name'    => ['nullable', 'string', 'max:190'],
            'owner_email'   => ['nullable', 'email', 'max:190'],
            'currency_code' => ['required', 'string', 'size:3'],
            'subdomain'     => ['required', 'string', 'max:80', 'alpha_dash'],
            'plan_id'       => ['nullable', 'exists:plans,id'],
            'trial_days'    => ['nullable', 'integer', 'min:0', 'max:365'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'tenant_code'   => strtolower(trim((string) $this->tenant_code)),
            'subdomain'     => strtolower(trim((string) $this->subdomain)),
            'currency_code' => strtoupper(trim((string) $this->currency_code)),
        ]);
    }
}
