<?php

namespace App\Http\Controllers\Tenant\Manufacturing;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Account;
use App\Models\Tenant\ManufacturingPostingSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Manufacturing Posting Settings.
 *
 * Manages the single tenant-default settings row (branch_id = null): the account
 * mapping + inventory policy manufacturing posting uses. Saving this form only
 * changes configuration; manufacturing documents perform their own posting.
 */
class ManufacturingPostingSettingController extends Controller
{
    /**
     * Account mapping field metadata: label, expected CoA type, required-to-enable.
     *
     * @var array<string, array{label:string, type:string, required:bool}>
     */
    private const FIELDS = [
        'raw_material_inventory_account_id'   => ['label' => 'Raw Material Inventory', 'type' => 'asset',   'required' => true],
        'wip_inventory_account_id'            => ['label' => 'Work In Process (WIP) Inventory', 'type' => 'asset', 'required' => true],
        'finished_goods_inventory_account_id' => ['label' => 'Finished Goods Inventory', 'type' => 'asset', 'required' => true],
        'manufacturing_overhead_account_id'   => ['label' => 'Manufacturing Overhead', 'type' => 'asset',   'required' => false],
        'direct_labour_account_id'            => ['label' => 'Direct Labour', 'type' => 'expense', 'required' => false],
        'scrap_expense_account_id'            => ['label' => 'Scrap / Waste Expense', 'type' => 'expense', 'required' => true],
        'rework_expense_account_id'           => ['label' => 'Rework Expense', 'type' => 'expense', 'required' => true],
        'production_variance_account_id'      => ['label' => 'Production Variance', 'type' => 'expense', 'required' => true],
        'manufactured_cogs_account_id'        => ['label' => 'Manufactured Goods COGS', 'type' => 'expense', 'required' => true],
        'inventory_adjustment_account_id'     => ['label' => 'Inventory Adjustment', 'type' => 'expense', 'required' => true],
    ];

    public function show()
    {
        $setting = $this->defaultSetting();

        return view('tenant.manufacturing.posting-settings.show', [
            'setting'  => $setting->load(array_map(fn ($k) => $this->relationFor($k), array_keys(self::FIELDS))),
            'fields'   => self::FIELDS,
        ]);
    }

    public function edit()
    {
        $setting = $this->defaultSetting();

        return view('tenant.manufacturing.posting-settings.edit', [
            'setting'  => $setting,
            'fields'   => self::FIELDS,
            'accounts' => Account::active()->orderBy('code')->get(['id', 'code', 'name', 'type']),
        ]);
    }

    public function update(Request $request)
    {
        $setting = $this->defaultSetting();

        // Base rules: each account nullable, must exist + be active.
        $rules = [
            'is_enabled'                  => ['nullable', 'boolean'],
            'negative_stock_policy'       => ['required', Rule::in(ManufacturingPostingSetting::NEGATIVE_STOCK_POLICIES)],
            'costing_method'              => ['required', Rule::in(ManufacturingPostingSetting::COSTING_METHODS)],
            'fg_cost_source'              => ['required', Rule::in(ManufacturingPostingSetting::FG_COST_SOURCES)],
            'notes'                       => ['nullable', 'string', 'max:2000'],
        ];
        foreach (array_keys(self::FIELDS) as $field) {
            $rules[$field] = ['nullable', 'integer', Rule::exists('accounts', 'id')->where('is_active', true)];
        }

        $validator = validator($request->all(), $rules);

        // Account-type guard + "enable requires all required mappings" guard.
        $validator->after(function ($v) use ($request) {
            $ids = array_filter(array_map(
                fn ($f) => $request->input($f) ?: null,
                array_combine(array_keys(self::FIELDS), array_keys(self::FIELDS))
            ));
            $accounts = $ids ? Account::whereIn('id', $ids)->get(['id', 'type'])->keyBy('id') : collect();

            foreach (self::FIELDS as $field => $meta) {
                $id = $request->input($field);
                if ($id && ($acc = $accounts->get((int) $id)) && $acc->type !== $meta['type']) {
                    $v->errors()->add($field, "{$meta['label']} must be a(n) {$meta['type']} account.");
                }
            }

            if ($request->boolean('is_enabled')) {
                foreach (self::FIELDS as $field => $meta) {
                    if ($meta['required'] && ! $request->input($field)) {
                        $v->errors()->add('is_enabled', "Cannot enable posting: \"{$meta['label']}\" account is required.");
                    }
                }
            }
        });

        $data = $validator->validate();

        // Persist configuration only — no posting, no ledger, no journal.
        $payload = ['updated_by_user_id' => auth('tenant')->id()];
        foreach (array_keys(self::FIELDS) as $field) {
            $payload[$field] = $request->input($field) ?: null;
        }
        $payload['is_enabled']            = $request->boolean('is_enabled');
        $payload['negative_stock_policy'] = $data['negative_stock_policy'];
        $payload['costing_method']        = $data['costing_method'];
        $payload['fg_cost_source']        = $data['fg_cost_source'];
        $payload['notes']                 = $data['notes'] ?? null;

        $setting->update($payload);

        return redirect(url('/manufacturing/posting-settings'))
            ->with('status', 'Manufacturing posting settings saved. Posting uses these mappings from each manufacturing document.');
    }

    /** Load-or-create the single tenant-default row (branch_id = null). Creates no posting data. */
    private function defaultSetting(): ManufacturingPostingSetting
    {
        return ManufacturingPostingSetting::firstOrCreate(
            ['branch_id' => null],
            [
                'is_enabled'            => false,
                'negative_stock_policy' => 'block',
                'costing_method'        => 'moving_average',
                'fg_cost_source'        => 'wip_actual',
                'created_by_user_id'    => auth('tenant')->id(),
            ]
        );
    }

    /** Map an account field key to its eager-load relation name. */
    private function relationFor(string $field): string
    {
        return \Illuminate\Support\Str::camel(str_replace('_id', '', $field));
    }
}
