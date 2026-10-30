<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Master\Tenant;
use App\Services\Central\TenantBusinessService;
use App\Support\Business;
use App\Support\TenantFeatures;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * PRIMARY-BUSINESS-1 — Bingoo Master: set a tenant's primary business and its switches.
 * A business change needs the tenant code typed back (it changes what staff see every day).
 */
class TenantBusinessController extends Controller
{
    public function __construct(private TenantBusinessService $service) {}

    public function impact(Tenant $tenant)
    {
        return response()->json($this->service->impact($tenant) + [
            'current' => $tenant->primary_business,
            'plan_modules' => $this->service->planModules($tenant),
        ]);
    }

    /** UB-10: read-only — which items the new screens take as they are (Master, before a switch goes on). */
    public function readiness(Tenant $tenant)
    {
        return response()->json($this->service->readiness($tenant));
    }

    public function updateBusiness(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'primary_business' => ['required', Rule::in(array_keys(Business::all()))],
            'confirm_code'     => ['required', 'string'],
            'note'             => ['nullable', 'string', 'max:255'],
        ]);
        if (trim(strtolower($data['confirm_code'])) !== strtolower($tenant->tenant_code)) {
            throw ValidationException::withMessages(['confirm_code' => 'Type the tenant code "' . $tenant->tenant_code . '" to confirm.']);
        }
        try {
            $defaults = $this->service->changeBusiness($tenant, $data['primary_business'], auth('central')->id(), $data['note'] ?? null);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['primary_business' => $e->getMessage()]);
        }
        // ROLLOUT-1: a business set for the first time — say which switches came on (a new tenant), or which are only
        // proposed (an older tenant, whose switches are kept as they are), by their Master labels.
        $labels = fn (array $keys) => implode(', ', array_map(fn ($k) => '"' . (TenantFeatures::all()[$k]['label'] ?? $k) . '"', $keys));
        $said = $defaults['added'] ? ' Switched on as this business\'s defaults: ' . $labels($defaults['added']) . '.' : '';
        if ($defaults['proposed']) {
            $said .= ' Proposed for this business, not switched on (this tenant was made before the defaults policy): '
                . $labels($defaults['proposed']) . ' — switch them on under Business & switches if wanted.';
        }

        return redirect('/tenants/' . $tenant->id . '#business')
            ->with('status', $tenant->business_name . ' is now: ' . Business::label($data['primary_business']) . '. No data was changed.' . $said);
    }

    public function updateFeatures(Request $request, Tenant $tenant)
    {
        $request->validate(['features' => ['nullable', 'array'], 'features.*' => ['boolean']]);
        $wanted = [];
        foreach (array_keys(TenantFeatures::all()) as $key) {
            $wanted[$key] = (bool) $request->input('features.' . $key, false);
        }
        $n = $this->service->setFeatures($tenant, $wanted, auth('central')->id());

        return redirect('/tenants/' . $tenant->id . '#features')
            ->with('status', $n ? "Saved: {$n} switch(es) changed." : 'Nothing changed.');
    }
}
