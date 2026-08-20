<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CateringCommercialRateApplication;
use App\Models\Tenant\CateringMaterialCommercialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\Product;
use App\Models\Tenant\Unit;
use App\Services\Catering\CateringCommercialRateBookService;
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
        private readonly CateringCommercialRateBookService $book,
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

    public function update(Request $request, CateringProductProfile $cateringProductProfile)
    {
        $data = $request->validate([
            'blocks' => ['array'],
            'blocks.*.id' => ['nullable', 'integer'],
            'blocks.*.label' => ['required', 'string', 'max:120'],
            'blocks.*.block_type' => ['required', Rule::in(CateringProductCostBlock::TYPES)],
            'blocks.*.charge_basis' => ['required', Rule::in(CateringProductCostBlock::BASES)],
            // What a material's rate is a rate OF. Absent on a charge block,
            // and absent on an untouched legacy row, which keeps its own.
            'blocks.*.rate_basis' => ['nullable', Rule::in(CateringProductCostBlock::RATE_BASES)],
            // Where the rate came from, and therefore whether a house rate change
            // is ever offered to it.
            'blocks.*.commercial_rate_source' => ['nullable', Rule::in(CateringProductCostBlock::RATE_SOURCES)],
            'blocks.*.rate' => ['required', 'numeric', 'min:0'],
            'blocks.*.material_product_id' => ['nullable', 'exists:products,id'],
            'blocks.*.quantity_per_unit' => ['nullable', 'numeric', 'min:0'],
            'blocks.*.unit_id' => ['nullable', 'exists:units,id'],
        ]);

        $submitted = collect($data['blocks'] ?? []);
        $productId = $cateringProductProfile->product_id;

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

        $existingBlocks = CateringProductCostBlock::where('product_id', $productId)
            ->get()->keyBy('id');

        $resolved = [];
        $links = [];

        foreach ($submitted->values() as $index => $row) {
            $existing = ! empty($row['id']) ? $existingBlocks->get((int) $row['id']) : null;

            $isMaterial = $row['block_type'] === CateringProductCostBlock::TYPE_MATERIAL;

            // A legacy row keeps the basis it was authored as unless the form
            // deliberately says otherwise. Defaulting an ABSENT value to the new
            // basis would silently reinterpret every untouched per-dish rate the
            // moment anyone renamed a block — the same stored number meaning a
            // different price, with nothing on screen to show it happened.
            $rateBasis = match (true) {
                ! $isMaterial => CateringProductCostBlock::RATE_PER_DISH_UNIT,
                ! empty($row['rate_basis']) => $row['rate_basis'],
                $existing !== null => $existing->rateBasis(),
                default => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
            };

            $source = $row['commercial_rate_source'] ?? CateringProductCostBlock::SOURCE_MANUAL;
            $rate = (float) $row['rate'];
            $unitId = $isMaterial ? ($row['unit_id'] ?? null) : null;
            $materialId = $isMaterial ? (int) $row['material_product_id'] : null;

            if ($source === CateringProductCostBlock::SOURCE_COMMERCIAL_BOOK) {
                // Server-side, not merely hidden in the UI: a form can be edited,
                // and each of these would produce a price that is wrong in a way
                // that reads as plausible.
                if (! $isMaterial) {
                    return $this->refuse($row['label'],
                        'a charge block is money for work, with no material behind it, so there is no '
                        .'house material rate for it to follow.');
                }
                if ($rateBasis !== CateringProductCostBlock::RATE_PER_MATERIAL_UNIT) {
                    return $this->refuse($row['label'],
                        'it is priced per unit of the DISH, and the house rate is per unit of the MATERIAL. '
                        .'Change "Rate is per" to the material unit first — that changes what the number '
                        .'means, so check the price afterwards.');
                }

                $bookRate = $this->book->effectiveRate($materialId);
                if ($bookRate === null) {
                    return $this->refuse($row['label'],
                        'this material has no house rate yet. Record one on the Commercial Material Rates '
                        .'screen first, then link the block to it.');
                }

                $bookRate->loadMissing('unit');
                $probe = new CateringProductCostBlock(['unit_id' => $unitId]);
                if (! $this->book->blockUnitMatches($bookRate, $probe)) {
                    // Said in the book service's own words, uncapitalised by
                    // nothing: "Unit mismatch — cannot apply" is the term this
                    // condition is named by on every screen, and an operator
                    // matching what they read here against what they read on the
                    // impact screen must find the same phrase.
                    return back()->withErrors([
                        'blocks' => "'{$row['label']}' — ".$this->book->unitMismatchReason(
                            $bookRate,
                            $unitId === null ? null : Unit::whereKey($unitId)->value('code')
                        ),
                    ])->withInput();
                }

                // Linking ADOPTS the current house rate as the applied one. From
                // then on the two move apart again: a later book change is only
                // ever offered, never taken.
                $wasLinked = $existing !== null && $existing->followsCommercialBook();
                if (! $wasLinked) {
                    $rate = (float) $bookRate->rate;
                    $links[$index] = [
                        'action' => CateringCommercialRateApplication::ACTION_BLOCK_LINKED,
                        'material_product_id' => $materialId,
                        'old' => $existing === null ? null : (float) $existing->rate,
                        'new' => $rate,
                    ];
                }
            } elseif ($existing !== null && $existing->followsCommercialBook()) {
                // Going back to a hand-set rate is equally deliberate, and keeps
                // whatever rate the form was showing as the explicit manual one.
                $links[$index] = [
                    'action' => CateringCommercialRateApplication::ACTION_BLOCK_UNLINKED,
                    'material_product_id' => $existing->material_product_id,
                    'old' => (float) $existing->rate,
                    'new' => $rate,
                ];
            }

            $resolved[$index] = [
                'attributes' => [
                    'product_id' => $productId,
                    'label' => $row['label'],
                    'block_type' => $row['block_type'],
                    'charge_basis' => $row['charge_basis'],
                    'rate' => $rate,
                    'sort_order' => $index + 1,
                    'is_active' => true,
                    // A charge block is money with nothing behind it, so it never
                    // carries a material or a consumption ratio however the form
                    // was filled in before the type was changed.
                    'material_product_id' => $materialId,
                    'quantity_per_unit' => $isMaterial ? ($row['quantity_per_unit'] ?? null) : null,
                    'rate_basis' => $rateBasis,
                    'commercial_rate_source' => $isMaterial
                        ? $source
                        : CateringProductCostBlock::SOURCE_MANUAL,
                    'unit_id' => $unitId,
                ],
                'existing' => $existing,
            ];
        }

        DB::connection('tenant')->transaction(function () use ($resolved, $links, $productId, $request) {
            $kept = [];

            foreach ($resolved as $index => $plan) {
                $existing = $plan['existing'];

                if ($existing) {
                    $existing->update($plan['attributes']);
                    $kept[] = $existing->id;
                    $blockId = $existing->id;
                } else {
                    $blockId = CateringProductCostBlock::create($plan['attributes'])->id;
                    $kept[] = $blockId;
                }

                if (isset($links[$index])) {
                    $link = $links[$index];
                    $this->book->record([
                        'material_product_id' => $link['material_product_id'],
                        'action' => $link['action'],
                        'target_type' => CateringCommercialRateApplication::TARGET_PRODUCT_BLOCK,
                        'target_id' => $blockId,
                        'target_label' => trim(Product::whereKey($productId)->value('name')
                            .' · '.$plan['attributes']['label']),
                        'old_commercial_rate' => $link['old'],
                        'new_commercial_rate' => $link['new'],
                        'performed_by_user_id' => $request->user()?->id,
                        'note' => $link['action'] === CateringCommercialRateApplication::ACTION_BLOCK_LINKED
                            ? 'Linked to the house rate book'
                            : 'Returned to a hand-set rate',
                    ]);
                }
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

    /** One shape for every refusal, so the operator always learns which part failed and why. */
    private function refuse(string $label, string $because)
    {
        return back()->withErrors([
            'blocks' => "'{$label}' cannot follow the house rate book: {$because}",
        ])->withInput();
    }
}
