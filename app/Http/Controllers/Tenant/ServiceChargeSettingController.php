<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\ServiceChargeSetting;
use Illuminate\Http\Request;

class ServiceChargeSettingController extends Controller
{
    public function index()
    {
        return view('tenant.service-charge-settings.index', [
            'branches' => Branch::where('status', 'active')->get(),
            'settings' => ServiceChargeSetting::with('branch')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id'     => ['required', 'exists:branches,id'],
            'charge_type'   => ['required', 'in:fixed,percent'],
            'charge_value'  => ['required', 'numeric', 'min:0'],
            'order_types'   => ['nullable', 'array'],
            'is_taxable'    => ['nullable', 'boolean'],
            'is_active'     => ['nullable', 'boolean'],
        ]);

        ServiceChargeSetting::updateOrCreate(
            ['branch_id' => $data['branch_id']],
            $data
        );

        return back()->with('status', 'Service charge setting updated successfully.');
    }
}
