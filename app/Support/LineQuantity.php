<?php

namespace App\Support;

use App\Models\Tenant\Product;
use App\Models\Tenant\Unit;
use Illuminate\Validation\ValidationException;

/**
 * W2-B (22 Oct) — one set of quantity rules for every new sale, hold, split and return line, checked on the server.
 *
 *  - Precision: a quantity is stored with 3 decimals (DECIMAL(…,3)). It is made exact ONCE, before price, stock or a
 *    return reads it, so the line total and the stock always use the same number. Float noise from the browser
 *    (0.30000000000000004) is cleaned; a real fourth decimal (1.2345 kg) is refused, never rounded silently.
 *  - Counted items (piece, bottle, plate, no unit at all) are whole numbers. Weight, volume and length items take
 *    parts (StandardUnits::allowsParts — the same test every screen already uses). Packs are whole (CounterPacks).
 *  - Returns: never more than is still returnable, said with its unit; a counted line comes back whole, or as
 *    everything still left — so a line that was split in the past (sold 1, 0.001 returned) can still be closed.
 *
 * Old lines are read exactly as stored; nothing here rewrites history.
 */
final class LineQuantity
{
    public const DECIMALS = 3;

    private const NOISE = 1e-6;   // float noise, far below a thousandth

    private const WHOLE = 0.0005; // half a thousandth: the stored precision

    /** The quantity as it will be stored, or a refusal when it has more decimals than can be stored. */
    public static function normalize($value, string $label, string $field = 'quantity'): float
    {
        $q = (float) $value;
        $exact = round($q, self::DECIMALS);
        if (abs($q - $exact) > self::NOISE) {
            throw ValidationException::withMessages([
                $field => "{$label}: a quantity can have at most 3 decimal places — " . self::show($q, 6) . ' has more.',
            ]);
        }

        return $exact;
    }

    public static function isWhole(float $q): bool
    {
        return abs($q - round($q)) < self::WHOLE;
    }

    public static function partsAllowed(?Unit $unit): bool
    {
        return StandardUnits::allowsParts($unit);
    }

    /**
     * Every sale or held line, made exact and checked, before anything reads it. Combo components and modifiers are
     * derived from their parent and are not checked here; a line in a pack is checked by CounterPacks (whole packs).
     */
    public static function prepareSaleLines(array $lines): array
    {
        $ids = collect($lines)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $products = $ids ? Product::with('unit')->whereIn('id', $ids)->get()->keyBy('id') : collect();
        $packsOn = CounterPacks::enabled();

        foreach ($lines as $i => $line) {
            if (! is_array($line) || ! isset($line['quantity']) || $line['quantity'] === '') {
                continue;
            }
            $product = $products->get((int) ($line['product_id'] ?? 0));
            $label = $product?->name ?? (string) ($line['line_name'] ?? 'Item');
            $qty = self::normalize($line['quantity'], $label, "lines.{$i}.quantity");
            $lines[$i]['quantity'] = $qty;

            $kind = $line['line_kind'] ?? 'standard';
            $inPack = $packsOn && ! empty($line['pack_unit_id']);
            if ($product && in_array($kind, ['standard', 'combo_header'], true) && ! $inPack
                && ! self::partsAllowed($product->unit) && ! self::isWhole($qty)) {
                throw ValidationException::withMessages(["lines.{$i}.quantity" => self::wholeMessage($label, self::unitName($product->unit), $qty)]);
            }
        }

        return $lines;
    }

    /**
     * W2-C (23 Oct) — a typed purchase or stock quantity (PO, GRN, supplier return, adjustment, transfer, count): made
     * exact to 3 decimals once, the same as a sale line. A counted item has to be whole only where the business has
     * switched that on: some live businesses buy counted items in parts, and their units stay as they are.
     */
    public static function forStock($value, ?Unit $unit, string $label, string $field = 'quantity'): float
    {
        $q = self::normalize($value, $label, $field);
        if (self::wholeCountedInStock() && ! self::partsAllowed($unit) && ! self::isWhole($q)) {
            throw ValidationException::withMessages([$field => self::wholeMessage($label, self::unitName($unit), $q)]);
        }

        return $q;
    }

    /**
     * W2-C: every typed purchase or stock line, made exact and checked before a total, the stock or a return reads it.
     * $field is the line's quantity key (quantity_ordered, quantity_received, quantity). A blank quantity is left to
     * the screen's own rule (a supplier return skips a blank line).
     */
    public static function prepareStockLines(array $lines, string $field = 'quantity'): array
    {
        $ids = collect($lines)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $products = $ids ? Product::with('unit')->whereIn('id', $ids)->get()->keyBy('id') : collect();

        foreach ($lines as $i => $line) {
            if (! is_array($line) || empty($line['product_id']) || ! isset($line[$field]) || $line[$field] === '') {
                continue;   // a row with no item is dropped by the screen's own rule
            }
            $product = $products->get((int) $line['product_id']);
            $lines[$i][$field] = self::forStock($line[$field], $product?->unit, $product?->name ?? 'Item', "lines.{$i}.{$field}");
        }

        return $lines;
    }

    public static function wholeCountedInStock(): bool
    {
        return TenantFeatures::on(TenantFeatures::WHOLE_COUNTED_STOCK);
    }

    public static function wholeMessage(string $label, string $unit, float $qty): string
    {
        return "{$label} is counted in whole numbers" . ($unit !== '' ? " ({$unit})" : '') . ' — ' . self::show($qty) . ' is not a whole number.';
    }

    /** The unit's name, or '' when the item has none (the message then says the number alone). */
    public static function unitName(?Unit $unit): string
    {
        return (string) ($unit?->name ?: ($unit?->code ?: ''));
    }

    /** "3 Strip", or "3" when there is no unit. */
    public static function withUnit(float $q, string $unit): string
    {
        return self::show($q) . ($unit !== '' ? " {$unit}" : '');
    }

    /** 1.5 → "1.5", 2.000 → "2", 0.25 → "0.25". */
    public static function show(float $q, int $decimals = self::DECIMALS): string
    {
        $s = number_format($q, $decimals, '.', '');

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
