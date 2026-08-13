<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CateringSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** CATERING-SLICE-1: tenant-level catering settings (singleton row). */
class CateringSettingController extends Controller
{
    public function index()
    {
        $settings = CateringSetting::tenantDefault();

        return view('tenant.catering.settings.index', compact('settings'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'reminder_recipient_email' => ['nullable', 'email', 'max:255'],
            'default_service_charge_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'print_language_profile' => ['required', Rule::in(CateringSetting::PRINT_PROFILES)],
            'reminder_offsets' => ['nullable', 'array'],
            'reminder_offsets.*' => [Rule::in(CateringSetting::DEFAULT_REMINDER_OFFSETS)],
        ]);

        $data['default_service_charge_percent'] = $data['default_service_charge_percent'] ?? 0;
        $data['reminder_offsets'] = array_values($data['reminder_offsets'] ?? []);

        CateringSetting::tenantDefault()->update($data);

        return back()->with('status', 'Catering settings saved.');
    }
}
