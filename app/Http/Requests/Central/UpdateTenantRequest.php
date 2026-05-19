<?php

namespace App\Http\Requests\Central;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('central')->check();
    }

    public function rules(): array
    {
        $tenant = $this->route('tenant');

        return [
            'tenant_code'   => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('tenants', 'tenant_code')->ignore($tenant?->id)],
            'business_name' => ['required', 'string', 'max:190'],
            'owner_name'    => ['nullable', 'string', 'max:190'],
            'owner_email'   => ['nullable', 'email', 'max:190'],
            'currency_code' => ['required', 'string', 'size:3'],
            'trial_ends_at' => ['nullable', 'date'],
            'plan_id'       => ['nullable', 'exists:plans,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'tenant_code'   => strtolower(trim((string) $this->tenant_code)),
            'currency_code' => strtoupper(trim((string) $this->currency_code)),
        ]);
    }
}
