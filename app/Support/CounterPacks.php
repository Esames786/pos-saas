<?php

namespace App\Support;

use App\Models\Tenant\Product;
use App\Models\Tenant\ProductPackUnit;
use RuntimeException;

/**
 * MEDICAL-COUNTER-1 — a counter line sold in a pack ("1 Strip", "2 Box", "3 Tablet").
 *
 * The line keeps the pack it was sold in: quantity = how many of that pack, unit price = the price of ONE
 * pack, so the money is exactly what the counter showed (a per-tablet price would lose paisa: the price column
 * keeps 2 decimals). Beside it, pack_factor (tablets in one pack) and base_quantity (tablets in the line) —
 * what stock, cost and returns read.
 *
 * Only on a tenant with the "Medical-store counter" switch (and packs) on; anywhere else a pack on a posted
 * line is ignored and the line is exactly today's.
 */
final class CounterPacks
{
    public static function enabled(): bool
    {
        return TenantFeatures::on(TenantFeatures::MEDICAL_COUNTER) && PackUnits::enabled();
    }

    /**
     * @return array{pack_unit_id:int, pack_factor:int, base_quantity:float, pack_price:float}|null  null = a plain line
     */
    public static function resolve(Product $product, array $line, float $quantity): ?array
    {
        $packId = (int) ($line['pack_unit_id'] ?? 0);
        if ($packId <= 0 || ! self::enabled()) {
            return null;
        }
        $pack = ProductPackUnit::whereKey($packId)->where('product_id', $product->id)->first();
        if (! $pack || ! $pack->can_sell) {
            throw new RuntimeException($product->name . ': that pack cannot be sold — pick another.');
        }
        if (abs($quantity - round($quantity)) > 1e-9) {
            throw new RuntimeException($product->name . ': ' . $pack->name . ' is sold in whole numbers.');
        }

        return [
            'pack_unit_id'  => (int) $pack->id,
            'pack_name'     => $pack->name,
            'pack_factor'   => (int) $pack->factor,
            'base_quantity' => round($quantity * $pack->factor, 3),
            'pack_price'    => $pack->priceFor($product),
        ];
    }

    /**
     * The batch the cashier picked on the counter, if it may be sold first: this product's, this branch's,
     * with stock, not expired. Anything else (or the switch off) = null = oldest expiry first, as always.
     */
    public static function preferredBatch(Product $product, int $branchId, mixed $batchId): ?int
    {
        $batchId = (int) $batchId;
        if ($batchId <= 0 || ! self::enabled()) {
            return null;
        }
        $today = app(TenantClock::class)->currentBusinessDate();
        $ok = \App\Models\Tenant\StockBalance::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $product->id)
            ->where('inventory_batch_id', $batchId)
            ->where('quantity_on_hand', '>', 0)
            ->whereHas('batch', fn ($b) => $b->where(fn ($e) => $e->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', $today)))
            ->exists();

        return $ok ? $batchId : null;
    }

    /**
     * What the counter needs to know about one product beyond today's payload: its packs (price of each),
     * whether it needs a prescription, and its batches with stock per branch (expired ones too — the counter
     * says it skips them). Null for a product with no packs and no batches, so the counter shows a plain line.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Tenant\StockBalance>  $balances  this product's balances with batch
     */
    public static function payload(Product $product, $balances, string $today): array
    {
        $packs = $product->packUnits
            ->filter(fn (ProductPackUnit $p) => $p->can_sell)
            ->sortByDesc('factor')
            ->map(fn (ProductPackUnit $p) => [
                'id'      => (int) $p->id,
                'name'    => $p->name,
                'factor'  => (int) $p->factor,
                'price'   => round($p->priceFor($product), 2),
                // a pack with its own price keeps it; otherwise the counter prices it factor × the item's
                // branch price, exactly like a loose tablet.
                'own'     => $p->sale_price !== null,
                'barcode' => $p->barcode,
            ])->values()->all();

        $batches = collect($balances)
            ->filter(fn ($b) => $b->batch && (float) $b->quantity_on_hand > 0)
            ->map(fn ($b) => [
                'id'        => (int) $b->inventory_batch_id,
                'branch_id' => (int) $b->branch_id,
                'variant_id' => $b->product_variant_id ? (int) $b->product_variant_id : null,
                'no'        => $b->batch->batch_no,
                'exp'       => $b->batch->expiry_date?->format('m/y'),
                'exp_date'  => $b->batch->expiry_date?->toDateString(),
                'expired'   => $b->batch->expiry_date && $b->batch->expiry_date->toDateString() < $today,
                'qty'       => round((float) $b->quantity_on_hand, 3),
            ])
            ->sortBy(fn ($b) => $b['exp_date'] ?? '9999-12-31')
            ->values()->all();

        return [
            'packs'      => $packs,
            'rx'         => (bool) ($product->needs_prescription ?? false),
            'strength'   => $product->strength ?? null,
            'has_expiry' => (bool) $product->has_expiry,
            'base_name'  => $product->unit?->name ?: 'Unit',
            'batches'    => $batches,
        ];
    }

    /** The stock quantity of a saved line: its tablets when it was sold in a pack, else its quantity. */
    public static function baseOf($line): float
    {
        return $line->base_quantity !== null && (int) $line->pack_factor > 0 ? (float) $line->base_quantity : (float) $line->quantity;
    }

    /** What the bill says under a pack line: "1 Strip = 10 Tablet · B:PN2410-07 EXP:09/28". */
    public static function receiptLine($line): ?string
    {
        $parts = [];
        if ($line->pack_unit_id && (int) $line->pack_factor > 1) {
            $pack = ProductPackUnit::find($line->pack_unit_id);
            $baseName = $line->product?->unit?->name ?: 'Tablet';
            $parts[] = rtrim(rtrim(number_format((float) $line->quantity, 3, '.', ''), '0'), '.') . ' ' . ($pack?->name ?? 'Pack')
                . ' = ' . rtrim(rtrim(number_format((float) $line->base_quantity, 3, '.', ''), '0'), '.') . ' ' . $baseName;
        }
        if ($line->batch_note) {
            $parts[] = 'B:' . $line->batch_note;
        }

        return $parts ? implode(' · ', $parts) : null;
    }
}
