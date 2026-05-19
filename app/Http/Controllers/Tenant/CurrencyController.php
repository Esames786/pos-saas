<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Currency;
use App\Models\Tenant\CurrencyDenomination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CurrencyController extends Controller
{
    public function index()
    {
        return view('tenant.currencies.index', [
            'currencies' => Currency::with('denominations')->orderByDesc('is_default')->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code'           => ['required', 'string', 'size:3', 'unique:currencies,code'],
            'name'           => ['required', 'string', 'max:190'],
            'symbol'         => ['required', 'string', 'max:10'],
            'decimal_places' => ['required', 'integer', 'min:0', 'max:4'],
            'is_default'     => ['nullable', 'boolean'],
            'is_active'      => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($data) {
            if (!empty($data['is_default'])) {
                Currency::query()->update(['is_default' => false]);
            }

            Currency::create([
                ...$data,
                'code'       => strtoupper($data['code']),
                'is_default' => !empty($data['is_default']),
                'is_active'  => !empty($data['is_active']),
            ]);
        });

        return back()->with('status', 'Currency created successfully.');
    }

    public function setDefault(Currency $currency)
    {
        Currency::query()->update(['is_default' => false]);
        $currency->update(['is_default' => true, 'is_active' => true]);

        return back()->with('status', 'Default currency updated.');
    }

    public function storeDenomination(Request $request, Currency $currency)
    {
        $data = $request->validate([
            'denomination_value' => [
                'required',
                'numeric',
                'min:0.01',
                Rule::unique('currency_denominations', 'denomination_value')
                    ->where('currency_id', $currency->id),
            ],
            'denomination_type' => ['required', Rule::in(['note', 'coin'])],
            'is_active'         => ['nullable', 'boolean'],
        ]);

        $currency->denominations()->create([
            'denomination_value' => $data['denomination_value'],
            'denomination_type'  => $data['denomination_type'],
            'is_active'          => !empty($data['is_active']),
        ]);

        return back()->with('status', 'Denomination added successfully.');
    }

    public function destroyDenomination(CurrencyDenomination $denomination)
    {
        $denomination->delete();

        return back()->with('status', 'Denomination deleted successfully.');
    }
}
