<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\Product;
use App\Models\Tenant\Unit;
use App\Services\Catering\CateringCostBlockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
 * Editing here posts nothing and moves no stock. It is configuration; the
 * consequences arrive when somebody quotes from it.
 */
class CateringCostBlockController extends Controller
{
    public function __construct(private readonly CateringCostBlockService $blocks) {}

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

    public function update(Request $request, CateringProductProfile $cateringProductProfile)
    {
        $data = $request->validate([
            'blocks' => ['array'],
            'blocks.*.id' => ['nullable', 'integer'],
            'blocks.*.label' => ['required', 'string', 'max:120'],
            'blocks.*.block_type' => ['required', Rule::in(CateringProductCostBlock::TYPES)],
            'blocks.*.charge_basis' => ['required', Rule::in(CateringProductCostBlock::BASES)],
            'blocks.*.rate' => ['required', 'numeric', 'min:0'],
            'blocks.*.material_product_id' => ['nullable', 'exists:products,id'],
            'blocks.*.quantity_per_unit' => ['nullable', 'numeric', 'min:0'],
            'blocks.*.unit_id' => ['nullable', 'exists:units,id'],
        ]);

        $submitted = collect($data['blocks'] ?? []);

        // A material block without a material is the one combination that would
        // quietly produce a charge nobody can trace to anything. Refused here
        // rather than accepted and reported later by readiness.
        $orphan = $submitted->first(fn ($b) => $b['block_type'] === CateringProductCostBlock::TYPE_MATERIAL
            && empty($b['material_product_id']));
        if ($orphan) {
            return back()->withErrors([
                'blocks' => "'{$orphan['label']}' is a material block, so it needs a material selected. "
                    .'Use a charge block for money with nothing behind it, like making or packing.',
            ])->withInput();
        }

        DB::connection('tenant')->transaction(function () use ($submitted, $cateringProductProfile) {
            $productId = $cateringProductProfile->product_id;
            $kept = [];

            foreach ($submitted->values() as $index => $row) {
                $attributes = [
                    'product_id' => $productId,
                    'label' => $row['label'],
                    'block_type' => $row['block_type'],
                    'charge_basis' => $row['charge_basis'],
                    'rate' => $row['rate'],
                    'sort_order' => $index + 1,
                    'is_active' => true,
                    // A charge block is money with nothing behind it, so it never
                    // carries a material or a consumption ratio however the form
                    // was filled in before the type was changed.
                    'material_product_id' => $row['block_type'] === CateringProductCostBlock::TYPE_MATERIAL
                        ? $row['material_product_id'] : null,
                    'quantity_per_unit' => $row['block_type'] === CateringProductCostBlock::TYPE_MATERIAL
                        ? ($row['quantity_per_unit'] ?? null) : null,
                    'unit_id' => $row['block_type'] === CateringProductCostBlock::TYPE_MATERIAL
                        ? ($row['unit_id'] ?? null) : null,
                ];

                $existing = ! empty($row['id'])
                    ? CateringProductCostBlock::where('product_id', $productId)->whereKey($row['id'])->first()
                    : null;

                if ($existing) {
                    $existing->update($attributes);
                    $kept[] = $existing->id;

                    continue;
                }

                $kept[] = CateringProductCostBlock::create($attributes)->id;
            }

            // Removed blocks are DEACTIVATED, not deleted. From Phase B a booking
            // line records which blocks it was priced from, and a deleted row
            // would leave those historical lines pointing at nothing.
            CateringProductCostBlock::where('product_id', $productId)
                ->whereNotIn('id', $kept ?: [0])
                ->update(['is_active' => false]);
        });

        // Tenant routes carry a {subdomain} parameter, so route() would bind the
        // profile to the subdomain and throw. A path avoids that entirely.
        return redirect()->to('/catering/profiles/'.$cateringProductProfile->id.'/blocks')
            ->with('status', 'Cost blocks saved. Rate is now '
                .number_format($this->blocks->rateFor($cateringProductProfile->product_id), 2).' per unit.');
    }
}
