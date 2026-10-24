<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\Unit;
use App\Services\Catering\CateringProfileWriter;
use Illuminate\Http\Request;

/**
 * CATERING-SLICE-1: manage catering product profiles (1:1 extension of the
 * shared Product) and the product's optional Urdu display name (which lives
 * in product_translations — the products table itself is never modified).
 */
class CateringProductProfileController extends Controller
{
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

    public function store(Request $request, CateringProfileWriter $writer)
    {
        $profile = $writer->save(null, $writer->validate($request->all()));

        return back()->with('status', "Catering profile saved for {$profile->product->name}.");
    }

    public function update(Request $request, CateringProductProfile $cateringProductProfile, CateringProfileWriter $writer)
    {
        $writer->save($cateringProductProfile, $writer->validate($request->all(), $cateringProductProfile));

        return back()->with('status', 'Catering profile updated.');
    }

    // W3/W4 S3b: the save, the costing-switch refusal (KASHIF-CATERING-COSTING-SOURCE-1) and the rules moved, unchanged
    // in what they save or say, to App\Services\Catering\CateringProfileWriter — shared with the product form.
}
