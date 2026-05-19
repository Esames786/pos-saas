<?php

namespace App\Http\Requests\Central;

use Illuminate\Foundation\Http\FormRequest;

class ProvisionTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('central')->check();
    }

    public function rules(): array
    {
        return [
            'owner_password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
