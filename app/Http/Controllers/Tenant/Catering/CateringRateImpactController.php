<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\Product;
use App\Services\Catering\CateringRateImpactService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CATERING-SLICE-2: Rate Impact Center. Only DRAFT estimates are listed and
 * only drafts can be updated; sent/accepted documents never appear here.
 */
class CateringRateImpactController extends Controller
{
    public function __construct(private readonly CateringRateImpactService $impact) {}

    public function index(Request $request)
    {
        $productId = (int) $request->input('product_id');
        $product = $productId ? Product::with('unit')->find($productId) : null;
        $currentRate = $product ? CateringMaterialRate::effectiveFor($product->id) : null;
        $rows = $product ? $this->impact->impactForProduct($product->id) : collect();

        return view('tenant.catering.rate-impact.index', compact('product', 'currentRate', 'rows'));
    }

    public function apply(Request $request)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['selected', 'all', 'skip'])],
            'product_id' => ['required', 'integer'],
            'estimate_ids' => ['array'],
            'estimate_ids.*' => ['integer'],
        ]);

        if ($data['action'] === 'skip') {
            return redirect()
                ->route('tenant.catering.material-rates.index')
                ->with('status', 'Existing drafts left unchanged; new quotations will use the new rate automatically.');
        }

        $ids = $data['action'] === 'all'
            ? $this->impact->impactForProduct((int) $data['product_id'])->pluck('estimate.id')->all()
            : ($data['estimate_ids'] ?? []);

        $updated = $this->impact->applyToDrafts($ids, $request->user()?->id);

        return redirect()
            ->route('tenant.catering.rate-impact.index', ['product_id' => $data['product_id']])
            ->with('status', "{$updated} draft estimate(s) repriced with the current rate book.");
    }
}
