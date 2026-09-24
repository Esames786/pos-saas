<?php

namespace App\Services\Sales;

use App\Models\Tenant\CashCountLine;
use App\Models\Tenant\Currency;
use Illuminate\Support\Collection;

/**
 * The ONE denomination-count rule, shared by the Online shift close (ShiftController) and the Branch Server shift close
 * (EdgeLocalShiftController) — extracted 25 Sep 2026 (parity directive: shared business rules are shared services, never an
 * Edge-only copy of a controller rule).
 *
 * Semantics (Online ShiftController@calculateCashCount, kept exactly):
 *  - an untouched grid — no positive quantity at all — is NO count (null), never a zero count;
 *  - a positive quantity typed against no KNOWN denomination of the default currency is also NO count (it must never close a
 *    drawer at 0.00 — the Edge guard, now applied on both sides);
 *  - otherwise the previous CashCountLine rows for this source are replaced and the total is Σ quantity × face value over
 *    the default currency's denominations.
 *
 * Both hosts run this on the `tenant` connection (the Cloud's active tenant database, or the appliance's local database
 * bound as `tenant` by IdentifyTenant).
 */
class CashCountService
{
    public const SOURCE_SHIFT = 'shift';

    /**
     * @param  array<int|string,mixed>  $quantities  denomination id => typed quantity
     * @return float|null the counted total, or null when nothing countable was typed
     */
    public function record(array $quantities, string $sourceType, int $sourceId): ?float
    {
        $any = collect($quantities)->contains(fn ($q) => (int) $q > 0);
        if ($quantities === [] || ! $any) {
            return null;
        }

        $denominations = $this->defaultDenominations();
        if (! $denominations->contains(fn ($d) => (int) ($quantities[$d->id] ?? 0) > 0)) {
            return null;
        }

        CashCountLine::on('tenant')->where('source_type', $sourceType)->where('source_id', $sourceId)->delete();

        $total = 0.0;
        foreach ($denominations as $denomination) {
            $quantity = (int) ($quantities[$denomination->id] ?? 0);
            $amount = $quantity * (float) $denomination->denomination_value;
            if ($quantity > 0) {
                CashCountLine::on('tenant')->create([
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'currency_denomination_id' => $denomination->id,
                    'quantity' => $quantity,
                    'amount' => $amount,
                ]);
            }
            $total += $amount;
        }

        return $total;
    }

    /** The default currency's denominations (empty when the tenant has none — the count then falls back to a typed total). */
    public function defaultDenominations(): Collection
    {
        return Currency::on('tenant')->where('is_default', true)->with('denominations')->first()?->denominations ?? collect();
    }
}
