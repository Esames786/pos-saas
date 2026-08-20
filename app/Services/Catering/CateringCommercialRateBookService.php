<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringCommercialRateApplication;
use App\Models\Tenant\CateringEstimateLineCostBlock;
use App\Models\Tenant\CateringMaterialCommercialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\Product;

/**
 * KASHIF-CATERING-COMMERCIAL-RATE-1 — reading the house rate book, checking that
 * a rate actually fits where it is about to go, and recording what was done.
 *
 * The reading half is trivial. The checking half is the reason this class
 * exists.
 *
 * THE UNIT PROBLEM. A house rate is quoted per unit of the MATERIAL: chicken at
 * 120 per KG. A cost block consumes the material in its own unit, and nothing so
 * far has forced those to be the same unit. So a block that uses 500 GM of
 * chicken per dish, offered a rate of 120 per KG, would arithmetically produce
 *
 *     500 x 120 = 60,000
 *
 * for half a kilo of chicken. Not a rounding error, not a bad suggestion — a
 * dimensional category error that reads as a plausible number, which is the
 * worst kind. It would be applied, quoted, and only discovered by a customer.
 *
 * This tranche deliberately does NOT introduce a conversion engine. Converting
 * GM to KG correctly means knowing which units are commensurable and by what
 * factor, per tenant, and getting that wrong silently is a larger risk than
 * refusing the case outright. So the contract is EXACT MATCH: a block may follow
 * the book only when the book's unit is literally the block's unit. Anything
 * else is refused, visibly, with the reason spelled out — "Unit mismatch —
 * cannot apply", never a number.
 *
 * That refusal is checked in five places, because a preview filter is not a
 * guard: linking a block, previewing a dish, applying to a dish, previewing a
 * quotation, applying to a quotation. Ids arrive from a form, and a form can be
 * edited.
 */
class CateringCommercialRateBookService
{
    public const UNIT_MISMATCH = 'Unit mismatch — cannot apply';

    /**
     * The house rate in force for a material on a date, or null when the
     * material has never been given one.
     */
    public function effectiveRate(int $materialProductId, ?string $asOfDate = null): ?CateringMaterialCommercialRate
    {
        return CateringMaterialCommercialRate::effectiveFor($materialProductId, $asOfDate);
    }

    /**
     * Does this book rate speak the same unit as this cost block?
     *
     * A block with no unit set at all is a mismatch, not a pass. "Unknown" is
     * exactly the state where an unchecked multiplication does its damage, so it
     * fails closed like every other unresolved case here.
     */
    public function blockUnitMatches(?CateringMaterialCommercialRate $rate, CateringProductCostBlock $block): bool
    {
        if ($rate === null || $rate->unit_id === null || $block->unit_id === null) {
            return false;
        }

        return (int) $rate->unit_id === (int) $block->unit_id;
    }

    /**
     * The same question for a quotation's snapshot, which keeps the unit as the
     * CODE it was quoted in rather than an id — the point of a snapshot being
     * that it still reads correctly after the units table has moved on.
     */
    public function snapshotUnitMatches(?CateringMaterialCommercialRate $rate, CateringEstimateLineCostBlock $snapshot): bool
    {
        if ($rate === null) {
            return false;
        }

        $bookUnit = trim((string) $rate->unit?->code);
        $quotedUnit = trim((string) $snapshot->unit_code);

        if ($bookUnit === '' || $quotedUnit === '') {
            return false;
        }

        return strcasecmp($bookUnit, $quotedUnit) === 0;
    }

    /** How a mismatch should be explained wherever it is shown. */
    public function unitMismatchReason(?CateringMaterialCommercialRate $rate, ?string $targetUnit): string
    {
        $bookUnit = trim((string) $rate?->unit?->code);
        $target = trim((string) $targetUnit);

        if ($bookUnit === '' || $target === '') {
            return self::UNIT_MISMATCH.' — the house rate is per '
                .($bookUnit !== '' ? $bookUnit : 'an unrecorded unit')
                .' and this one is priced in '
                .($target !== '' ? $target : 'no recorded unit')
                .'. Set both to the same unit first.';
        }

        return self::UNIT_MISMATCH." — the house rate is per {$bookUnit} and this one is priced in {$target}. "
            .'They are not converted automatically, because a wrong conversion would look like a valid price.';
    }

    /**
     * Write one line of the record.
     *
     * Always called INSIDE the transaction that made the change, so a rolled
     * back application can never leave an audit row claiming it succeeded.
     */
    public function record(array $attributes): CateringCommercialRateApplication
    {
        if (empty($attributes['material_name']) && ! empty($attributes['material_product_id'])) {
            $attributes['material_name'] = Product::whereKey($attributes['material_product_id'])->value('name');
        }

        return CateringCommercialRateApplication::create($attributes);
    }
}
