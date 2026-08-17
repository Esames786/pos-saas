<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\Product;
use App\Models\Tenant\Unit;
use App\Services\Catering\CateringLocalizationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CATERING-SLICE-1: manage catering product profiles (1:1 extension of the
 * shared Product) and the product's optional Urdu display name (which lives
 * in product_translations — the products table itself is never modified).
 */
class CateringProductProfileController extends Controller
{
    public function __construct(private readonly CateringLocalizationService $localization) {}

    public function index(Request $request)
    {
        $search = trim((string) $request->input('q', ''));

        // The two counts drive the switch warning: an operator changing the
        // costing source needs to know the other side's configuration is still
        // there (dormant, not deleted) before they decide.
        $profiles = CateringProductProfile::with([
            'product.translations', 'product.unit', 'defaultQuoteUnit',
            'product.activeRecipe.ingredients',
        ])
            ->withCount(['costBlocks as active_block_count' => fn ($q) => $q->where('is_active', true)])
            ->when($search !== '', fn ($query) => $query->whereHas(
                'product',
                fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")
            ))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $units = Unit::where('is_active', true)->orderBy('name')->get();

        return view('tenant.catering.profiles.index', compact('profiles', 'units', 'search'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $profile = CateringProductProfile::updateOrCreate(
            ['product_id' => $data['product_id']],
            collect($data)->except(['product_id', 'name_ur'])->all()
        );

        $this->localization->setProductName(
            Product::findOrFail($data['product_id']),
            CateringLocalizationService::LOCALE_URDU,
            $data['name_ur'] ?? null
        );

        return back()->with('status', "Catering profile saved for {$profile->product->name}.");
    }

    public function update(Request $request, CateringProductProfile $cateringProductProfile)
    {
        $data = $this->validated($request, $cateringProductProfile);

        $cateringProductProfile->update(collect($data)->except(['product_id', 'name_ur'])->all());

        $this->localization->setProductName(
            $cateringProductProfile->product,
            CateringLocalizationService::LOCALE_URDU,
            $data['name_ur'] ?? null
        );

        return back()->with('status', 'Catering profile updated.');
    }

    private function validated(Request $request, ?CateringProductProfile $profile = null): array
    {
        return $request->validate([
            'product_id' => [
                $profile ? 'nullable' : 'required',
                'exists:products,id',
            ],
            'catering_enabled' => ['nullable', 'boolean'],
            'default_quote_unit_id' => ['nullable', 'exists:units,id'],
            'pricing_mode' => ['required', Rule::in(CateringProductProfile::PRICING_MODES)],
            // KASHIF-CATERING-COSTING-SOURCE-1: which authority decides this
            // dish's cost. Separate from pricing_mode above and never inferred
            // from it — the operator chooses it deliberately, per product.
            'costing_mode' => ['required', Rule::in(CateringProductProfile::COSTING_MODES)],
            'default_catering_rate' => ['nullable', 'numeric', 'min:0'],
            'production_station' => ['nullable', 'string', 'max:50'],
            'minimum_qty' => ['nullable', 'numeric', 'min:0'],
            'production_label' => ['nullable', 'string', 'max:255'],
            'production_label_ur' => ['nullable', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'name_ur' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
