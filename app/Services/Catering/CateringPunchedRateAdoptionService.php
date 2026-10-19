<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CATERING-ADOPT-PUNCHED-RATE-1 — the dead end becomes one decision.
 *
 * A dish priced from cost blocks that HAS no cost blocks stops its quotation
 * from being sent at all. Until now the only way out was to leave the booking,
 * open Cost Blocks, add a part by hand, and come back — and what operators
 * actually did was add an empty block at rate 0 purely to unlock the door.
 * "Chatni", "Paratha (Pcs)" and "Decoration" on the live tenant all carry one.
 * Decoration sold for 45,000 with a cost basis of nothing.
 *
 * So the offer is made where the wall is, and it carries a real number: the
 * rate the operator already typed on this very quotation.
 *
 * THREE THINGS THIS DELIBERATELY WILL NOT DO:
 *
 *  1. It never reads a rate from the request. The browser says only WHICH dish
 *     was agreed to; the amount is read here, from the line. Otherwise the
 *     dialog would be a way to set any price on any dish from a form post.
 *
 *  2. It only ever creates a CHARGE block — money for work, with nothing behind
 *     it. A material block needs a material, a unit and a quantity per unit, and
 *     a half-built one fails readiness again two screens later. Whoever wants
 *     the material breakdown adds it on the Cost Blocks screen, which is the
 *     screen for it.
 *
 *  3. It refuses any dish that already has an active block. This is not the
 *     path for changing a price — only for giving one to a dish that has none.
 *
 * The rate becomes the dish's STANDING rate, on every later quotation. That is
 * the point and also the risk, so the screen says it before anything is done.
 */
class CateringPunchedRateAdoptionService
{
    /**
     * Dishes on this estimate that are blocked for having no cost blocks at all,
     * with the rate this quotation already carries for each.
     *
     * @return array<int, array{product_id:int, name:string, rate:float, quantity:float, unit:?string}>
     */
    public function offersFor(CateringEstimate $estimate): array
    {
        $lines = $estimate->lines()->whereNotNull('product_id')->get();
        if ($lines->isEmpty()) {
            return [];
        }

        // Dishes actually priced from blocks. A dish costed some other way is
        // not blocked by this and must not be offered a block it never wanted.
        $blockPriced = CateringProductProfile::query()
            ->whereIn('product_id', $lines->pluck('product_id')->unique())
            ->where('costing_mode', CateringProductProfile::COSTING_BLOCKS)
            ->pluck('product_id')
            ->all();

        $offers = [];
        foreach ($lines as $line) {
            $pid = (int) $line->product_id;
            if (! in_array($pid, $blockPriced, true) || isset($offers[$pid])) {
                continue;
            }
            if ($this->activeBlockCount($pid) > 0) {
                continue;
            }

            $offers[$pid] = [
                'product_id' => $pid,
                'name' => $line->item_name,
                'rate' => round((float) $line->rate, 2),
                'quantity' => (float) $line->quantity,
                'unit' => $line->unit_code,
            ];
        }

        return array_values($offers);
    }

    /**
     * Give each named dish a charge block at the rate this quotation carries.
     *
     * @param  int[]  $productIds  which dishes the operator agreed to — not what they cost
     * @return array<int, string>  what was created, for the confirmation message
     */
    public function adopt(CateringEstimate $estimate, array $productIds, ?int $userId = null): array
    {
        if ($productIds === []) {
            return [];
        }

        // Keyed by product so the request cannot name a dish that is not on this
        // quotation, and cannot pick which of several lines to take a rate from.
        $offers = collect($this->offersFor($estimate))->keyBy('product_id');

        $created = [];
        foreach (array_unique(array_map('intval', $productIds)) as $pid) {
            $offer = $offers->get($pid);
            if ($offer === null) {
                // Silently skipping would let a stale screen appear to work.
                throw new RuntimeException(
                    'That dish is not waiting for a rate on this quotation. Reload the booking and try again.'
                );
            }

            DB::connection('tenant')->transaction(function () use ($pid, $offer, $userId, &$created) {
                // Re-checked inside the transaction: between the screen being
                // drawn and this running, somebody else may have added the very
                // block this would duplicate.
                if ($this->activeBlockCount($pid) > 0) {
                    throw new RuntimeException(
                        "'{$offer['name']}' already has a cost block now — nothing was changed. Reload the booking."
                    );
                }

                $profile = CateringProductProfile::where('product_id', $pid)->first();
                if ($profile === null) {
                    throw new RuntimeException("'{$offer['name']}' has no catering profile, so it cannot take a rate here.");
                }

                CateringProductCostBlock::create([
                    'product_id' => $pid,
                    'label' => $this->labelFor($offer['name']),
                    'block_type' => CateringProductCostBlock::TYPE_CHARGE,
                    'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
                    'rate_basis' => CateringProductCostBlock::RATE_PER_DISH_UNIT,
                    'commercial_rate_source' => CateringProductCostBlock::SOURCE_MANUAL,
                    'rate' => $offer['rate'],
                    'is_active' => true,
                    'sort_order' => 1,
                ]);

                $created[] = $offer['name'].' at '.number_format($offer['rate'], 2);
            });
        }

        return $created;
    }

    private function activeBlockCount(int $productId): int
    {
        return CateringProductCostBlock::where('product_id', $productId)->where('is_active', true)->count();
    }

    /**
     * The convention already on the live tenant: "Chatni C.B", "Paratha C.B".
     * Matching it means an operator reading the Cost Blocks screen sees one kind
     * of name, not two.
     */
    private function labelFor(string $dish): string
    {
        return mb_substr(trim($dish), 0, 116).' C.B';
    }
}
