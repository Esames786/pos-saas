<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanFeature;
use App\Models\Master\PlanModule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    public function index()
    {
        $plans = Plan::with(['features', 'modules'])
            ->orderBy('code')
            ->get();

        return view('central.plans.index', compact('plans'));
    }

    public function edit(Plan $plan)
    {
        $plan->load(['features', 'modules']);

        $modules = Module::orderBy('sort_order')->orderBy('name')->get();

        $features = $plan->features
            ->pluck('feature_value', 'feature_key')
            ->toArray();

        $planModules = $plan->planModules()
            ->with('module')
            ->get()
            ->keyBy('module_id');

        return view('central.plans.edit', compact('plan', 'modules', 'features', 'planModules'));
    }

    public function update(Request $request, Plan $plan)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency_code' => ['required', 'string', 'size:3'],
            'billing_period' => ['required', Rule::in(['monthly', 'yearly'])],
            'is_active' => ['nullable', 'boolean'],

            'enabled_modules' => ['nullable', 'array'],
            'enabled_modules.*' => ['integer', 'exists:modules,id'],

            'module_limits' => ['nullable', 'array'],
            'module_limits.*' => ['nullable', 'string'],

            'features' => ['nullable', 'array'],
            'features.branch_limit' => ['nullable', 'integer', 'min:0'],
            'features.user_limit' => ['nullable', 'integer', 'min:0'],
            'features.terminal_limit' => ['nullable', 'integer', 'min:0'],
        ]);

        $plan->update([
            'name' => $data['name'],
            'price' => $data['price'],
            'currency_code' => strtoupper($data['currency_code']),
            'billing_period' => $data['billing_period'],
            'is_active' => $request->boolean('is_active'),
        ]);

        $enabledModuleIds = collect($data['enabled_modules'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->all();

        $moduleLimits = $data['module_limits'] ?? [];

        foreach (Module::all() as $module) {
            $limits = null;
            $rawLimits = trim((string) ($moduleLimits[$module->id] ?? ''));

            if ($rawLimits !== '') {
                $decoded = json_decode($rawLimits, true);

                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                    return back()
                        ->withInput()
                        ->withErrors([
                            'module_limits.' . $module->id => 'Limits must be valid JSON object/array.',
                        ]);
                }

                $limits = $decoded;
            }

            PlanModule::updateOrCreate(
                [
                    'plan_id' => $plan->id,
                    'module_id' => $module->id,
                ],
                [
                    'is_enabled' => in_array($module->id, $enabledModuleIds, true),
                    'limits' => $limits,
                ]
            );
        }

        foreach (['branch_limit', 'user_limit', 'terminal_limit'] as $featureKey) {
            $value = $data['features'][$featureKey] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            PlanFeature::updateOrCreate(
                [
                    'plan_id' => $plan->id,
                    'feature_key' => $featureKey,
                ],
                [
                    'feature_value' => (string) $value,
                ]
            );
        }

        return redirect('/plans')->with('status', 'Plan updated successfully.');
    }
}
