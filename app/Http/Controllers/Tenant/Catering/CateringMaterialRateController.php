<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\Unit;
use Illuminate\Http\Request;

/**
 * CATERING-SLICE-2: Material Rate Book — versioned catering quote rates.
 * Writes only catering_material_rates; inventory costs and POS prices are
 * never touched from here.
 */
class CateringMaterialRateController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->input('q', ''));

        $latestRates = CateringMaterialRate::with(['product.unit', 'unit', 'product.translations'])
            ->whereIn('id', function ($query) {
                $query->selectRaw('max(id)')
                    ->from('catering_material_rates')
                    ->groupBy('product_id');
            })
            ->when($search !== '', fn ($query) => $query->whereHas(
                'product',
                fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")
            ))
            ->orderByDesc('effective_from')
            ->paginate(25)
            ->withQueryString();

        $history = null;
        if ($productId = (int) $request->input('product_id')) {
            $history = CateringMaterialRate::with(['unit', 'product'])
                ->where('product_id', $productId)
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->get();
        }

        $units = Unit::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);

        return view('tenant.catering.material-rates.index', compact('latestRates', 'history', 'units', 'search'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'rate' => ['required', 'numeric', 'min:0'],
            'unit_id' => ['nullable', 'exists:units,id'],
            'effective_from' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $data['created_by_user_id'] = $request->user()?->id;

        $rate = CateringMaterialRate::create($data);

        return redirect()
            ->route('tenant.catering.rate-impact.index', ['product_id' => $rate->product_id])
            ->with('status', "Rate recorded for {$rate->product->name} — review the impact below.");
    }
}
