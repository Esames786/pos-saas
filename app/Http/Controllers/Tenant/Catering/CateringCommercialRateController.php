<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CateringMaterialCommercialRate;
use App\Models\Tenant\Product;
use App\Models\Tenant\Unit;
use App\Services\Catering\CateringCommercialRateImpactService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * KASHIF-CATERING-COMMERCIAL-RATE-1 — the house price list, and what changing it
 * would do.
 *
 * Two screens' worth of one idea. The rate book says what a material is CHARGED
 * at — a different question from what it costs, which lives in Material Rates
 * and is not touched here. Raising a rate records the new one and changes
 * nothing else: no dish, no quotation, no invoice.
 *
 * What it produces instead is a review. Which dishes follow the house rate, what
 * they would become, which drafts were quoted at the old one and by how much
 * they would move — and then a selection. There is no apply-everything, because
 * a caterer's premium counter and their wedding package are supposed to differ
 * from the house rate, and a bulk button is how those get quietly flattened.
 */
class CateringCommercialRateController extends Controller
{
    public function __construct(private readonly CateringCommercialRateImpactService $impact) {}

    public function index(Request $request)
    {
        $latest = CateringMaterialCommercialRate::query()
            ->with(['product:id,name,sku', 'unit:id,code'])
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->get()
            ->unique('product_id')
            ->values();

        return view('tenant.catering.commercial-rates.index', [
            'rates' => $latest,
            'materials' => Product::query()
                ->whereIn('product_kind', [
                    Product::KIND_RAW_MATERIAL,
                    Product::KIND_PACKAGING_MATERIAL,
                    Product::KIND_SEMI_FINISHED,
                ])
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'unit_id']),
            'units' => Unit::where('is_active', true)->orderBy('code')->get(['id', 'code']),
        ]);
    }

    /**
     * Record a new house rate. Effective-dated, so the old one survives and a
     * quotation priced at it stays explicable.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'rate' => ['required', 'numeric', 'min:0'],
            'unit_id' => ['nullable', 'exists:units,id'],
            'effective_from' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        CateringMaterialCommercialRate::updateOrCreate(
            [
                'product_id' => $data['product_id'],
                'effective_from' => $data['effective_from'],
            ],
            [
                'rate' => $data['rate'],
                'unit_id' => $data['unit_id'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by_user_id' => $request->user()?->id,
            ]
        );

        $name = Product::whereKey($data['product_id'])->value('name');

        return redirect()->to('/catering/commercial-rates')->with('status',
            "{$name} is now charged at ".number_format((float) $data['rate'], 2).' per unit. '
            .'Nothing has been repriced — review the impact to decide what should follow it.');
    }

    /** What this rate change would do, to dishes and to drafts. */
    public function impact(Request $request, Product $product)
    {
        return view('tenant.catering.commercial-rates.impact', [
            'material' => $product,
            'impact' => $this->impact->productImpact($product->id),
            'drafts' => $this->impact->draftImpact($product->id),
        ]);
    }

    /** Apply the house rate to the dishes the operator picked. */
    public function applyToProducts(Request $request, Product $product)
    {
        $data = $request->validate([
            'block_ids' => ['required', 'array', 'min:1'],
            'block_ids.*' => ['integer'],
        ]);

        try {
            $applied = $this->impact->applyToProducts($product->id, $data['block_ids']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['impact' => $e->getMessage()]);
        }

        return redirect()->to('/catering/commercial-rates/'.$product->id.'/impact')
            ->with('status', $applied === 0
                ? 'Nothing was changed — none of those dishes follow the house rate.'
                : "{$applied} dish(es) now charge the house rate. Quotations already sent or drafted are untouched.");
    }

    /** Apply it to the draft quotations the operator picked. */
    public function applyToDrafts(Request $request, Product $product)
    {
        $data = $request->validate([
            'snapshot_ids' => ['required', 'array', 'min:1'],
            'snapshot_ids.*' => ['integer'],
        ]);

        try {
            $applied = $this->impact->applyToDrafts($product->id, $data['snapshot_ids']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['impact' => $e->getMessage()]);
        }

        return redirect()->to('/catering/commercial-rates/'.$product->id.'/impact')
            ->with('status', $applied === 0
                ? 'Nothing was changed — none of those quotations could take the house rate.'
                : "{$applied} quotation line(s) repriced.");
    }
}
