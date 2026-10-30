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
 * UNITS-PACKS batch (23 Oct): a pack may hold a part (Bag = 2.5 kg). pack_size keeps the exact size; pack_factor (a
 * whole-number column) is kept only when the size is whole. Read a line's size with self::sizeOf(), never pack_factor.
 *
 * Only on a tenant with the "Medical-store counter" switch (and packs) on; anywhere else a pack on a posted
 * line is ignored and the line is exactly today's.
 */
final class CounterPacks
{
    /** The medical-store counter: its own layout, batches, prescriptions. */
    public static function enabled(): bool
    {
        return TenantFeatures::on(TenantFeatures::MEDICAL_COUNTER) && PackUnits::enabled();
    }

    /** UNITS-PACKS F3: a line may be sold in a pack — at the medical counter, or at the normal counter with its switch. */
    public static function packsOn(): bool
    {
        return (TenantFeatures::on(TenantFeatures::MEDICAL_COUNTER) || TenantFeatures::on(TenantFeatures::COUNTER_PACKS)) && PackUnits::enabled();
    }

    /** UNITS-PACKS F3: the normal counter's pack line takes the pack's price from the server, never the screen's. */
    public static function serverPricesPacks(): bool
    {
        return TenantFeatures::on(TenantFeatures::COUNTER_PACKS) && ! TenantFeatures::on(TenantFeatures::MEDICAL_COUNTER) && PackUnits::enabled();
    }

    /**
     * UNITS-PACKS F3 (review, 23 Oct): stock safety is not a medical-counter screen feature. Where either counter sells
     * packs, an item with an expiry date never sells from an expired batch. Prescriptions stay medical-only.
     */
    public static function refusesExpired(): bool
    {
        return TenantFeatures::on(TenantFeatures::MEDICAL_COUNTER) || TenantFeatures::on(TenantFeatures::COUNTER_PACKS);
    }

    /**
     * UNITS-PACKS F3: what the normal counter needs to sell an item's packs: each sellable pack bigger than one piece,
     * with its own price (null = size × the item's price at that branch, as the server works it out).
     *
     * @return list<array{id:int, name:string, factor:float, own_price:?float, barcode:?string}>
     */
    public static function counterPacks(Product $product): array
    {
        return $product->packUnits
            ->filter(fn (ProductPackUnit $p) => $p->can_sell && (float) $p->factor > 1)
            ->sortBy('factor')
            ->map(fn (ProductPackUnit $p) => ['id' => (int) $p->id, 'name' => $p->name, 'factor' => (float) $p->factor,
                'own_price' => $p->sale_price !== null ? (float) $p->sale_price : null, 'barcode' => $p->barcode])
            ->values()->all();
    }

    /**
     * @return array{pack_unit_id:int, pack_name:string, pack_factor:?int, pack_size:float, base_quantity:float, pack_price:float}|null  null = a plain line
     */
    public static function resolve(Product $product, array $line, float $quantity): ?array
    {
        $packId = (int) ($line['pack_unit_id'] ?? 0);
        if ($packId <= 0 || ! self::packsOn()) {
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
            'pack_factor'   => self::wholeOrNull($pack->factor),
            'pack_size'     => (float) $pack->factor,
            'base_quantity' => round($quantity * $pack->factor, 3),
            'pack_price'    => $pack->priceFor($product),
            'pack_own_price' => $pack->sale_price !== null,
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
                'factor'  => (float) $p->factor,
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

    /** The size of the pack a saved line was sold in (2.5 for a 2.5 kg Bag), 0 for a plain line. */
    public static function sizeOf($line): float
    {
        $size = $line->pack_size ?? null;

        return (float) ($size !== null && (float) $size > 0 ? $size : ($line->pack_factor ?? 0));
    }

    /**
     * ['pack_size' => …] when the table has the column, else nothing: the column ships before the code that writes it
     * (schema first), and a line written meanwhile simply has no size, as before.
     */
    public static function sizeColumns(string $table, $size): array
    {
        static $has = [];
        $key = \Illuminate\Support\Facades\DB::connection('tenant')->getDatabaseName() . '.' . $table;
        $has[$key] ??= \Illuminate\Support\Facades\Schema::connection('tenant')->hasColumn($table, 'pack_size');

        return $has[$key] && $size !== null && (float) $size > 0 ? ['pack_size' => round((float) $size, 4)] : [];
    }

    /** pack_factor is a whole-number column: a size of 2.5 is kept in pack_size only. */
    public static function wholeOrNull(float $size): ?int
    {
        return abs($size - round($size)) < 1e-9 ? (int) round($size) : null;
    }

    /** The stock quantity of a saved line: its tablets when it was sold in a pack, else its quantity. */
    public static function baseOf($line): float
    {
        return $line->base_quantity !== null && self::sizeOf($line) > 0 ? (float) $line->base_quantity : (float) $line->quantity;
    }

    /** What the bill says under a pack line: "1 Strip = 10 Tablet · B:PN2410-07 EXP:09/28". */
    /**
     * UNITS-PACKS F4: what a line's number is, in words — "2 Bag = 5 Kilogram" for a line sold in a pack, "250 g =
     * 0.25 Kilogram" for one typed in grams. A plain line gets null and prints exactly as it always has.
     */
    public static function lineWords($line): ?string
    {
        $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
        $unit = $line->product?->unit;
        if ($line->pack_unit_id && self::sizeOf($line) > 1) {
            $pack = ProductPackUnit::find($line->pack_unit_id);

            return $num($line->quantity) . ' ' . ($pack?->name ?? 'Pack') . ' = ' . $num($line->base_quantity) . ' ' . ($unit?->name ?: 'Tablet');
        }
        $code = (string) ($line->entered_unit_code ?? '');
        $factor = $code !== '' && $line->entered_quantity !== null ? StandardUnits::codeFactor($code, $unit) : null;
        if ($factor !== null && abs($factor - 1) > 1e-9) {
            return trim($num($line->entered_quantity) . ' ' . StandardUnits::entryLabel($code) . ' = ' . $num($line->quantity) . ' ' . ($unit?->name ?? ''));
        }

        return null;
    }

    public static function receiptLine($line): ?string
    {
        $parts = array_filter([self::lineWords($line)]);
        if ($line->batch_note) {
            $parts[] = 'B:' . $line->batch_note;
        }

        return $parts ? implode(' · ', $parts) : null;
    }
}
