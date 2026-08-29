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
        // KASHIF-EVENT-FORM-1: the house's own sittings, managed right here.
        $timePresets = \App\Models\Tenant\CateringServiceTimePreset::ordered()->get();

        return view('tenant.catering.settings.index', compact('settings', 'timePresets'));
    }

    /**
     * KASHIF-EVENT-FORM-1 — save the sittings list in one act.
     *
     * Rows the owner blanked out are removed, the rest are stored with their
     * order; nothing else on the settings page is touched.
     */
    public function saveTimePresets(Request $request)
    {
        $data = $request->validate([
            'presets' => ['array'],
            'presets.*.id' => ['nullable', 'integer'],
            'presets.*.label' => ['nullable', 'string', 'max:60'],
            'presets.*.service_time' => ['nullable', 'date_format:H:i'],
            'presets.*.is_active' => ['nullable', 'boolean'],
        ]);

        $keep = [];
        foreach (array_values($data['presets'] ?? []) as $index => $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $time = $row['service_time'] ?? null;
            if ($label === '' || ! $time) {
                continue; // a blanked row is a removed row
            }
            $preset = \App\Models\Tenant\CateringServiceTimePreset::findOrNew((int) ($row['id'] ?? 0));
            $preset->fill([
                'label' => $label,
                'service_time' => $time,
                'sort_order' => ($index + 1) * 10,
                'is_active' => (bool) ($row['is_active'] ?? false),
            ])->save();
            $keep[] = $preset->id;
        }

        \App\Models\Tenant\CateringServiceTimePreset::whereNotIn('id', $keep ?: [0])->delete();

        return back()->with('status', 'Service times saved — the event screen offers them now.');
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
