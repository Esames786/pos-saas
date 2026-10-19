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
            // ONLINE-ORDERING-1 (Phase 5): `website` = client ka apna poora domain (sirf ordering website).
            'kind' => ['nullable', 'in:app,website'],
            'subdomain' => ['required_unless:kind,website', 'nullable', 'string', 'max:80', 'alpha_dash'],
            'website_domain' => ['required_if:kind,website', 'nullable', 'string', 'max:190',
                'regex:/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Website domain jaisa bhi chipkaya jaye (https://www.KashifFood.com/menu) — sirf `kashiffood.com`.
        $site = strtolower(trim((string) $this->website_domain));
        $site = preg_replace('#^[a-z]+://#', '', $site);
        $site = preg_replace('#[/?\#].*$#', '', (string) $site);
        $site = preg_replace('#^www\.#', '', rtrim((string) $site, '.'));

        $this->merge([
            'subdomain' => strtolower(trim((string) $this->subdomain)),
            'website_domain' => $site,
        ]);
    }

    public function kind(): string
    {
        return $this->input('kind') === TenantDomain::KIND_WEBSITE ? TenantDomain::KIND_WEBSITE : TenantDomain::KIND_APP;
    }

    public function fullDomain(): string
    {
        if ($this->kind() === TenantDomain::KIND_WEBSITE) {
            return (string) $this->website_domain;
        }

        return $this->subdomain . '.' . config('tenancy.tenant_base_domain');
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $domain = $this->fullDomain();

            if (TenantDomain::where('domain', $domain)->exists()) {
                $validator->errors()->add('subdomain', 'This subdomain is already used.');
            }

            // Website domain bingoopos.com khud ya central nahi ho sakta. Us ka subdomain chalta hai (UAT, jaise
            // kashiffoodweb.bingoopos.com) — wahan sirf website khulti hai (TenantOnly).
            $base = (string) config('tenancy.tenant_base_domain');
            if ($this->kind() === TenantDomain::KIND_WEBSITE
                && ($domain === $base || $domain === config('tenancy.central_domain'))) {
                $validator->errors()->add('website_domain', 'Website domain must be the client\'s own domain, not '.$base.'.');
            }
        });
    }
}
