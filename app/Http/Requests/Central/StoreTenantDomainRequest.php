<?php

namespace App\Http\Requests\Central;

use App\Models\Master\TenantDomain;
use Illuminate\Foundation\Http\FormRequest;

class StoreTenantDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('central')->check();
    }

    public function rules(): array
    {
        return [
            'subdomain' => ['required', 'string', 'max:80', 'alpha_dash'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'subdomain' => strtolower(trim((string) $this->subdomain)),
        ]);
    }

    public function fullDomain(): string
    {
        return $this->subdomain . '.' . config('tenancy.tenant_base_domain');
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $domain = $this->fullDomain();

            if (TenantDomain::where('domain', $domain)->exists()) {
                $validator->errors()->add('subdomain', 'This subdomain is already used.');
            }
        });
    }
}
