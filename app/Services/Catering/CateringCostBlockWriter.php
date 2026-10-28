<?php

namespace App\Services\Catering;

use App\Http\Controllers\Tenant\Catering\CateringCommercialRateController;
use App\Models\Tenant\CateringCommercialRateApplication;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\Product;
use App\Models\Tenant\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * W3/W4 S3b — the one place a dish's cost blocks are written, shared by the Cost Blocks screen and (S3c) the product
 * workspace. Moved here from CateringCostBlockController without changing what it saves or refuses: every refusal keeps
 * its words, but is now THROWN (a ValidationException on "blocks"), so a caller inside a bigger transaction rolls back
 * instead of carrying on after a returned redirect. The screen sees the same redirect back with the same error.
 *
 * Editing here posts nothing and moves no stock. It is configuration; the consequences arrive when somebody quotes
 * from it.
 */
final class CateringCostBlockWriter
{
    public function __construct(private readonly CateringCommercialRateBookService $book) {}

    /** The rules the Cost Blocks screen has always used. */
    public static function rules(): array
    {
        return [
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
            // KASHIF-CATERING-MAKING-1: an explicit role, never inferred from the
            // label. Charge blocks only; at most one active Making per dish.
            'blocks.*.charge_role' => ['nullable', Rule::in(CateringProductCostBlock::CHARGE_ROLES)],
            'blocks.*.rate' => ['required', 'numeric', 'min:0'],
            // The material select only offers things the store can hand over, but
            // a select box is a convenience and the request is the contract. A
            // block pointed at a finished dish would consume a dish from stock
            // and follow a "material rate" for something that is not a material.
            'blocks.*.material_product_id' => ['nullable', Rule::exists('products', 'id')->where(
                fn ($q) => $q->whereIn('product_kind', CateringCommercialRateController::MATERIAL_KINDS)
            )],
            'blocks.*.quantity_per_unit' => ['nullable', 'numeric', 'min:0'],
            // A retired unit cannot be matched against the house rate book, so it
            // must not become a block's unit in the first place.
            'blocks.*.unit_id' => ['nullable', Rule::exists('units', 'id')->where('is_active', true)],
        ];
    }

    /**
     * @param  array  $attributes  names for the messages
     * @throws ValidationException
     */
    public function validate(array $input, array $attributes = []): array
    {
        return Validator::make($input, self::rules(), [], $attributes)->validate();
    }

    /**
     * Work out every block's row, and every link to / from the house rate book, without writing anything.
     *
     * @return array{resolved: array<int, array{attributes: array, existing: ?CateringProductCostBlock}>, links: array<int, array>}
     * @throws ValidationException  the screen's refusals, in the screen's words
     */
    public function plan(int $productId, array $blocks): array
    {
        $submitted = collect($blocks);

        // A material block without a material is the one combination that would
        // quietly produce a charge nobody can trace to anything. Refused here
        // rather than accepted and reported later by readiness.
        $orphan = $submitted->first(fn ($b) => $b['block_type'] === CateringProductCostBlock::TYPE_MATERIAL
            && empty($b['material_product_id']));
        if ($orphan) {
            throw ValidationException::withMessages([
                'blocks' => "'{$orphan['label']}' is a material block, so it needs a material selected. "
                    .'Use a charge block for money with nothing behind it, like making or packing.',
            ]);
        }

        // KASHIF-CATERING-MAKING-1: "the Making charge" is singular by meaning.
        // Two blocks both claiming the role would make a bulk Making adjustment
        // ambiguous about which money it moves — refused at the door.
        $makingRows = $submitted->filter(fn ($b) => ($b['block_type'] ?? null) === CateringProductCostBlock::TYPE_CHARGE
            && ($b['charge_role'] ?? null) === CateringProductCostBlock::ROLE_MAKING);
        if ($makingRows->count() > 1) {
            throw ValidationException::withMessages([
                'blocks' => 'Only one charge can be the Making charge for a dish. '
                    .'Leave the others as General Charge.',
            ]);
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
                    $this->refuse($row['label'],
                        'a charge block is money for work, with no material behind it, so there is no '
                        .'house material rate for it to follow.');
                }
                if ($rateBasis !== CateringProductCostBlock::RATE_PER_MATERIAL_UNIT) {
                    $this->refuse($row['label'],
                        'it is priced per unit of the DISH, and the house rate is per unit of the MATERIAL. '
                        .'Change "Rate is per" to the material unit first — that changes what the number '
                        .'means, so check the price afterwards.');
                }

                $bookRate = $this->book->effectiveRate($materialId);
                if ($bookRate === null) {
                    $this->refuse($row['label'],
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
                    throw ValidationException::withMessages([
                        'blocks' => "'{$row['label']}' — ".$this->book->unitMismatchReason(
                            $bookRate,
                            $unitId === null ? null : Unit::whereKey($unitId)->value('code')
                        ),
                    ]);
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
                    // A role names what a CHARGE is; a material can never carry
                    // one, however the form was filled before a type change.
                    'charge_role' => $isMaterial ? null : ($row['charge_role'] ?? null),
                    'unit_id' => $unitId,
                ],
                'existing' => $existing,
            ];
        }

        return ['resolved' => $resolved, 'links' => $links];
    }

    /** Write a plan: rows updated or created, links recorded, every block not in it switched off. One transaction. */
    public function apply(int $productId, array $plan, ?int $userId): void
    {
        DB::connection('tenant')->transaction(function () use ($plan, $productId, $userId) {
            $kept = [];

            foreach ($plan['resolved'] as $index => $row) {
                $existing = $row['existing'];

                if ($existing) {
                    $existing->update($row['attributes']);
                    $kept[] = $existing->id;
                    $blockId = $existing->id;
                } else {
                    $blockId = CateringProductCostBlock::create($row['attributes'])->id;
                    $kept[] = $blockId;
                }

                if (isset($plan['links'][$index])) {
                    $link = $plan['links'][$index];
                    $this->book->record([
                        'material_product_id' => $link['material_product_id'],
                        'action' => $link['action'],
                        'target_type' => CateringCommercialRateApplication::TARGET_PRODUCT_BLOCK,
                        'target_id' => $blockId,
                        'target_label' => trim(Product::whereKey($productId)->value('name')
                            .' · '.$row['attributes']['label']),
                        'old_commercial_rate' => $link['old'],
                        'new_commercial_rate' => $link['new'],
                        'performed_by_user_id' => $userId,
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
    }

    /** Check and write in one call — what the Cost Blocks screen does. */
    public function save(int $productId, array $blocks, ?int $userId): void
    {
        $this->apply($productId, $this->plan($productId, $blocks), $userId);
    }

    /**
     * W3/W4 S3: the plan would write nothing new — the same active blocks, the same values, in the same order, and no
     * link to or from the house rate book. The product workspace then leaves the blocks alone.
     */
    public function unchanged(int $productId, array $plan): bool
    {
        if ($plan['links'] !== []) {
            return false;
        }
        $active = CateringProductCostBlock::where('product_id', $productId)->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $planned = collect($plan['resolved'])->map(fn ($r) => $r['existing']?->id)->all();
        if (in_array(null, $planned, true) || collect($planned)->map(fn ($id) => (int) $id)->sort()->values()->all() !== $active) {
            return false;
        }
        $norm = fn ($v) => is_bool($v) ? (int) $v : (is_numeric($v) ? (float) $v : ($v === '' ? null : $v));
        foreach ($plan['resolved'] as $row) {
            foreach ($row['attributes'] as $k => $v) {
                if ($norm($row['existing']->getRawOriginal($k)) != $norm($v)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** One shape for every refusal, so the operator always learns which part failed and why. */
    private function refuse(string $label, string $because): never
    {
        throw ValidationException::withMessages([
            'blocks' => "'{$label}' cannot follow the house rate book: {$because}",
        ]);
    }
}
