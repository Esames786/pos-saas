<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CateringMaterialCommercialRate;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\Product;
use App\Models\Tenant\Unit;
use App\Services\Catering\CateringCostBlockService;
use App\Services\Catering\CateringCostBlockWriter;
use Illuminate\Http\Request;

/**
 * KASHIF-CATERING-COST-BLOCKS-1 — building a dish price out of named parts.
 *
 * Kashif prices a dish by adding up what goes into it: chicken 200 + making 500
 * = 700 per KG. When chicken moves, the dish moves with it. This screen is where
 * those parts are written down.
 *
 * The screen has one job beyond data entry, and it is the reason the preview
 * exists: a material block carries TWO numbers that look alike and are not.
 * What the customer pays for chicken, and how much chicken the kitchen actually
 * draws, are independent. Showing the charge and the requirement side by side
 * is how an operator sees that 10 KG of biryani bills 2,000 of chicken while
 * five kilos leave the store.
 *
 * KASHIF-CATERING-COMMERCIAL-RATE-1 adds the third question to a material block,
 * and it is the one that makes the house rate book operational: WHERE DOES THIS
 * RATE COME FROM. A block that follows the book is offered every later house
 * change; a block that was priced by hand is left alone. Without a control here
 * the book would exist and nothing would ever be eligible for it, which is a
 * feature that is present and unusable.
 *
 * Linking is deliberate in both directions, never inferred. A material merely
 * HAVING a house rate is not consent to follow it — the premium counter charging
 * 140 while the book says 120 is the case the whole design protects.
 *
 * Editing here posts nothing and moves no stock. It is configuration; the
 * consequences arrive when somebody quotes from it.
 */
class CateringCostBlockController extends Controller
{
    public function __construct(
        private readonly CateringCostBlockService $blocks,
    ) {}

    public function edit(CateringProductProfile $cateringProductProfile)
    {
        $cateringProductProfile->load('product.unit');

        return view('tenant.catering.cost-blocks.edit', [
            'profile' => $cateringProductProfile,
            'blocks' => $this->blocks->blocksFor($cateringProductProfile->product_id),
            'readiness' => $this->blocks->readiness($cateringProductProfile->product_id),
            'rate' => $this->blocks->rateFor($cateringProductProfile->product_id),
            'units' => Unit::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            // Things the store can actually hand over. A dish is not a material,
            // so sale items are deliberately absent from this list.
            // KASHIF-CATERING-STORE-2 (help text): what each material actually
            // costs today, so the preview can show the difference between what
            // the customer is charged and what the kitchen pays. READ-ONLY — the
            // Material Rate Book stays the only place a rate is edited.
            'materialRates' => \App\Models\Tenant\CateringMaterialRate::query()
                ->whereDate('effective_from', '<=', now()->toDateString())
                ->orderBy('product_id')
                ->orderBy('effective_from')
                ->orderBy('id')
                ->get(['product_id', 'rate'])
                ->keyBy('product_id')
                ->map(fn ($row) => (float) $row->rate),
            // What the house CHARGES for each material today, with the unit it is
            // quoted in — so the operator linking a block can see the rate they
            // are about to adopt and whether it even speaks the same unit.
            'commercialRates' => $this->commercialRateLookup(),
            'materials' => Product::query()
                ->whereIn('product_kind', [
                    Product::KIND_RAW_MATERIAL,
                    Product::KIND_PACKAGING_MATERIAL,
                    Product::KIND_SEMI_FINISHED,
                ])
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'unit_id']),
        ]);
    }

    /** @return array<int, array{rate: float, unit_id: ?int, unit_code: ?string, effective_from: string}> */
    private function commercialRateLookup(): array
    {
        return CateringMaterialCommercialRate::query()
            ->with('unit:id,code')
            ->whereDate('effective_from', '<=', now()->toDateString())
            ->orderBy('effective_from')->orderBy('id')
            ->get()
            // Ordered oldest first, so the last row written for a material wins
            // — the same "latest effective_from, then latest id" rule the book
            // itself resolves "current" by.
            ->keyBy('product_id')
            ->map(fn ($row) => [
                'rate' => (float) $row->rate,
                'unit_id' => $row->unit_id === null ? null : (int) $row->unit_id,
                'unit_code' => $row->unit?->code,
                'effective_from' => $row->effective_from?->toDateString(),
            ])
            ->all();
    }

    public function update(Request $request, CateringProductProfile $cateringProductProfile, CateringCostBlockWriter $writer)
    {
        // W3/W4 S3b: the checks, the house-rate links and the save moved, unchanged in what they save or say, to
        // App\Services\Catering\CateringCostBlockWriter — shared with the product form. A refusal is thrown there and
        // comes back here as the same redirect with the same error on "blocks".
        $data = $writer->validate($request->all());
        $writer->save($cateringProductProfile->product_id, $data['blocks'] ?? [], $request->user()?->id);

        // Tenant routes carry a {subdomain} parameter, so route() would bind the
        // profile to the subdomain and throw. A path avoids that entirely.
        return redirect()->to('/catering/profiles/'.$cateringProductProfile->id.'/blocks')
            ->with('status', 'Cost blocks saved. Rate is now '
                .number_format($this->blocks->rateFor($cateringProductProfile->product_id), 2).' per unit.');
    }
}
