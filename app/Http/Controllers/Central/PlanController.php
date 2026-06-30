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

        $moduleSummaries = $this->moduleSummaries();
        $moduleLimitDefinitions = $this->moduleLimitDefinitions();
        $categoryOrder = $this->categoryOrder();

        return view('central.plans.edit', compact(
            'plan',
            'modules',
            'features',
            'planModules',
            'moduleSummaries',
            'moduleLimitDefinitions',
            'categoryOrder'
        ));
    }

    public function update(Request $request, Plan $plan)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'monthly_price' => ['nullable', 'numeric', 'min:0'],
            'yearly_price' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['required', 'string', 'size:3'],
            'billing_period' => ['required', Rule::in(['monthly', 'yearly'])],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'public_description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'is_public' => ['nullable', 'boolean'],
            'is_custom' => ['nullable', 'boolean'],

            'enabled_modules' => ['nullable', 'array'],
            'enabled_modules.*' => ['integer', 'exists:modules,id'],

            'module_limits' => ['nullable', 'array'],
            'module_limits.*' => ['nullable', 'string'],
            'module_limit_fields' => ['nullable', 'array'],
            'module_limit_fields.*' => ['nullable', 'array'],
            'module_limit_fields.*.*' => ['nullable', 'string', 'max:120'],

            'features' => ['nullable', 'array'],
            'features.branch_limit' => ['nullable', 'integer', 'min:0'],
            'features.user_limit' => ['nullable', 'integer', 'min:0'],
            'features.terminal_limit' => ['nullable', 'integer', 'min:0'],
            'features.product_limit' => ['nullable', 'integer', 'min:0'],
        ]);

        $plan->update([
            'name' => $data['name'],
            'price' => $data['price'],
            'monthly_price' => $data['monthly_price'] ?? null,
            'yearly_price' => $data['yearly_price'] ?? null,
            'currency_code' => strtoupper($data['currency_code']),
            'billing_period' => $data['billing_period'],
            'trial_days' => $data['trial_days'] ?? null,
            'public_description' => $data['public_description'] ?? null,
            'is_active' => $request->boolean('is_active'),
            'is_public' => $request->boolean('is_public'),
            'is_custom' => $request->boolean('is_custom'),
        ]);

        $enabledModuleIds = collect($data['enabled_modules'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->all();

        $moduleLimits = $data['module_limits'] ?? [];
        $moduleLimitFields = $data['module_limit_fields'] ?? [];
        $moduleLimitDefinitions = $this->moduleLimitDefinitions();
        $existingPlanModules = $plan->planModules()->get()->keyBy('module_id');

        foreach (Module::all() as $module) {
            $limits = $existingPlanModules[$module->id]->limits ?? null;
            $rawSubmitted = array_key_exists($module->id, $moduleLimits) || array_key_exists((string) $module->id, $moduleLimits);
            $rawLimits = trim((string) ($moduleLimits[$module->id] ?? ''));

            if ($rawSubmitted) {
                if ($rawLimits === '') {
                    $limits = null;
                } else {
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
            }

            $friendlyFields = $moduleLimitFields[$module->id] ?? [];
            $friendlyKeys = array_keys($moduleLimitDefinitions[$module->key] ?? []);

            if ($friendlyFields || $friendlyKeys) {
                $limits = is_array($limits) ? $limits : [];

                foreach ($friendlyKeys as $key) {
                    unset($limits[$key]);
                }

                foreach ($friendlyFields as $key => $value) {
                    if (! in_array($key, $friendlyKeys, true)) {
                        continue;
                    }

                    $value = trim((string) $value);

                    if ($value === '') {
                        continue;
                    }

                    $limits[$key] = is_numeric($value) ? $value + 0 : $value;
                }

                if ($limits === []) {
                    $limits = null;
                }
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

        foreach (['branch_limit', 'user_limit', 'terminal_limit', 'product_limit'] as $featureKey) {
            $value = $data['features'][$featureKey] ?? null;

            if ($value === null || $value === '') {
                PlanFeature::updateOrCreate(
                    [
                        'plan_id' => $plan->id,
                        'feature_key' => $featureKey,
                    ],
                    [
                        'feature_value' => null,
                    ]
                );

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

    private function categoryOrder(): array
    {
        return [
            'Sales',
            'Core',
            'Inventory',
            'Purchasing',
            'Restaurant',
            'Operations',
            'Analytics',
            'Controls',
            'Administration',
            'Finance',
            'Manufacturing / ERP',
        ];
    }

    private function moduleSummaries(): array
    {
        return [
            'pos' => [
                'description' => 'Allows checkout, sales orders, payments, held sales, returns, customers, and POS screen.',
                'sees' => 'POS checkout, orders, payments, customers, and sales ledger.',
            ],
            'catalog' => [
                'description' => 'Allows products, categories, units, modifiers, barcodes, and branch pricing.',
                'sees' => 'Product catalog, units, categories, modifiers, and barcode tools.',
            ],
            'inventory' => [
                'description' => 'Allows stock on hand, stock adjustments, and branch transfers.',
                'sees' => 'Inventory dashboard, stock adjustments, and transfer screens.',
            ],
            'stock_count' => [
                'description' => 'Allows physical stock count workflows and variance checks.',
                'sees' => 'Stock count sessions and count review screens.',
            ],
            'purchasing' => [
                'description' => 'Allows suppliers, purchase orders, goods receipts, bills, and supplier payments.',
                'sees' => 'Purchasing documents, suppliers, receipts, and payables.',
            ],
            'restaurant' => [
                'description' => 'Allows tables, floors, waiters, dine-in sessions, and restaurant operations.',
                'sees' => 'Restaurant floors, tables, waiters, and table sessions.',
            ],
            'kitchen_display' => [
                'description' => 'Allows kitchen display screens for live preparation queues.',
                'sees' => 'Kitchen display and order preparation screens.',
            ],
            'kitchen_inventory' => [
                'description' => 'Allows kitchen recipes and ingredient consumption workflows.',
                'sees' => 'Kitchen recipes, ingredients, production, and wastage tools.',
            ],
            'printing' => [
                'description' => 'Allows print agents, terminals, KOT, receipts, and print job monitoring.',
                'sees' => 'Printing setup, print agents, and job status screens.',
            ],
            'reports' => [
                'description' => 'Allows operational, sales, purchase, kitchen, and dashboard reports.',
                'sees' => 'Analytics dashboards and report menus.',
            ],
            'sales_controls' => [
                'description' => 'Allows promotions, combos/deals, void reasons, and service charge settings.',
                'sees' => 'Promotions, deals, void controls, and service-charge setup.',
            ],
            'multi_branch' => [
                'description' => 'Allows branches, terminals, shifts, closings, and currency controls.',
                'sees' => 'Branch, terminal, shift, daily closing, and currency setup.',
            ],
            'users_roles' => [
                'description' => 'Allows tenant users, roles, and permission management.',
                'sees' => 'User, role, and permission administration.',
            ],
            'finance' => [
                'description' => 'Allows chart of accounts, cash/bank, expenses, journals, trial balance, P&L, and balance sheet.',
                'sees' => 'Finance ledgers, cash/bank, journals, expenses, and statements.',
            ],
            'manufacturing' => [
                'description' => 'Allows BOM, production orders, WIP, finished goods, consumption, and manufacturing reports.',
                'sees' => 'BOM, production orders, WIP, receipts, requisitions, and manufacturing reports.',
            ],
        ];
    }

    private function moduleLimitDefinitions(): array
    {
        return [
            'pos' => [
                'terminal_limit' => 'Terminals',
                'daily_sales_limit' => 'Daily sales limit',
                'branch_limit' => 'Branches',
            ],
            'catalog' => [
                'product_limit' => 'Products',
                'category_limit' => 'Categories',
            ],
            'inventory' => [
                'stock_location_limit' => 'Stock locations / branches',
                'stock_counts_per_month' => 'Stock counts per month',
            ],
            'restaurant' => [
                'table_limit' => 'Tables',
                'floor_limit' => 'Floors',
                'waiter_limit' => 'Waiters',
            ],
            'finance' => [
                'manual_journals_per_month' => 'Manual journals per month',
                'export_access' => 'Export access',
            ],
            'manufacturing' => [
                'production_orders_per_month' => 'Production orders per month',
                'bom_limit' => 'BOM count',
            ],
        ];
    }
}
