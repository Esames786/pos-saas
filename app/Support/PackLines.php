<?php

namespace App\Support;

use App\Models\Tenant\Product;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * PURCHASING-V2-1 — the entry-area lines of the new PO / GRN screens, turned into the lines the existing
 * purchase code already saves.
 *
 * The entry area sends each line as typed by a person: boxes per pack ("2 Box 3 Strip"), the price of ONE
 * buying pack, a discount %, a free (bonus) quantity, batch and expiry as MM/YY. This class turns that into
 * today's line: quantity in the product's own unit (tablets) and the net cost of ONE tablet — so stock,
 * the supplier's bill, voids, returns and reports read exactly what they read today. The pack the price was
 * given in is kept beside it (pack_unit_id / pack_factor / base_quantity) only so screens can say "5 Box".
 *
 * Rules:
 *  - The SERVER computes every number; the page's own sums are never trusted.
 *  - A free (bonus) quantity becomes its own line at zero cost, same batch and expiry (GRN only): stock
 *    goes up by it, the bill does not.
 *  - Line discount is taken into the unit cost: the supplier's bill reads quantity × unit cost and ignores
 *    a GRN line's discount column.
 *  - Whole numbers for packs; part quantities only on kg / litre / metre items.
 *  - A GRN never receives an already-expired batch.
 */
final class PackLines
{
    /**
     * @param  array<int,array<string,mixed>>  $lines  the posted entry-area lines
     * @param  string  $qtyField  'quantity_received' (GRN) or 'quantity_ordered' (PO)
     * @return list<array<string,mixed>>  lines in the existing request shape
     */
    public static function toLines(array $lines, string $qtyField, bool $isGrn): array
    {
        $lines = array_values(array_filter($lines, fn ($l) => is_array($l) && ! empty($l['product_id'])));
        $products = Product::with(['unit', 'packUnits'])->whereIn('id', array_map(fn ($l) => (int) $l['product_id'], $lines))->get()->keyBy('id');
        $packsOn = PackUnits::enabled();
        $today = Carbon::now('Asia/Karachi')->startOfDay();
        $errors = [];
        $out = [];

        foreach ($lines as $i => $l) {
            $p = $products[(int) $l['product_id']] ?? null;
            if (! $p) {
                $errors["lines.$i.product_id"] = 'Line ' . ($i + 1) . ': this item no longer exists.';
                continue;
            }
            $part = in_array($p->unit?->unit_type, ['weight', 'volume', 'length'], true);
            $packs = self::packsOf($p, $packsOn);
            $name = $p->name;

            // Quantity: the boxes typed, each in its own pack.
            $base = 0.0;
            foreach ((array) ($l['packs'] ?? []) as $key => $raw) {
                $raw = trim((string) $raw);
                if ($raw === '') {
                    continue;
                }
                $pack = $packs[(string) $key] ?? null;
                if (! $pack || ! is_numeric($raw) || (float) $raw < 0) {
                    $errors["lines.$i.packs"] = "{$name}: the quantity must be a number.";
                    continue 2;
                }
                if (! $part && floor((float) $raw) != (float) $raw) {
                    $errors["lines.$i.packs"] = "{$name}: {$pack['name']} must be a whole number.";
                    continue 2;
                }
                $base += (float) $raw * $pack['factor'];
            }

            // Price: of ONE buying pack, less the discount.
            $buy = $packs[(string) ($l['buy_pack'] ?? '')] ?? self::biggestBuyable($packs);
            $priceRaw = str_replace(',', '', trim((string) ($l['price'] ?? '0')));
            $discRaw = str_replace(',', '', trim((string) ($l['disc'] ?? '0')));
            if (! is_numeric($priceRaw) || (float) $priceRaw < 0) {
                $errors["lines.$i.price"] = "{$name}: the price must be a number.";
                continue;
            }
            if ($discRaw !== '' && (! is_numeric($discRaw) || (float) $discRaw < 0 || (float) $discRaw > 100)) {
                $errors["lines.$i.disc"] = "{$name}: the discount is a percent from 0 to 100.";
                continue;
            }
            $net = (float) $priceRaw * (1 - (float) ($discRaw ?: 0) / 100) / $buy['factor'];

            // Free (bonus) packs, GRN only.
            $freeRaw = trim((string) ($l['free'] ?? ''));
            $free = 0.0;
            if ($isGrn && $freeRaw !== '') {
                if (! is_numeric($freeRaw) || (float) $freeRaw < 0 || (! $part && floor((float) $freeRaw) != (float) $freeRaw)) {
                    $errors["lines.$i.free"] = "{$name}: the free quantity must be a whole number of {$buy['name']}.";
                    continue;
                }
                $free = (float) $freeRaw * $buy['factor'];
            }

            if ($base <= 0 && $free <= 0) {
                $errors["lines.$i.packs"] = "{$name}: how many? Type it in any of the boxes.";
                continue;
            }

            $expiry = null;
            if ($isGrn) {
                $expiry = self::expiry($l['expiry'] ?? null);
                if ($expiry === false) {
                    $errors["lines.$i.expiry_date"] = "{$name}: write the expiry as month/year, for example 09/28.";
                    continue;
                }
                if ($expiry && Carbon::parse($expiry)->lt($today)) {
                    $errors["lines.$i.expiry_date"] = "{$name}: this batch has already expired — it cannot be received. Return it to the supplier.";
                    continue;
                }
                // The same rule the GRN applies later, asked here so the page comes back in its own shape.
                if ($p->is_stock_tracked && $p->requires_batch && trim((string) ($l['batch_no'] ?? '')) === '') {
                    $errors["lines.$i.batch_no"] = "{$name}: write the batch number printed on the box.";
                    continue;
                }
                if ($p->is_stock_tracked && $p->has_expiry && ! $expiry) {
                    $errors["lines.$i.expiry_date"] = "{$name}: write the expiry (month/year).";
                    continue;
                }
            }

            $common = [
                'product_id'             => $p->id,
                'product_variant_id'     => $l['product_variant_id'] ?? null,
                'pack_unit_id'           => $buy['id'],
                'pack_factor'            => $buy['factor'],
            ] + ($isGrn ? [
                'batch_no'               => ($b = strtoupper(trim((string) ($l['batch_no'] ?? '')))) !== '' ? $b : null,
                'expiry_date'            => $expiry ?: null,
            ] : []);

            if ($base > 0) {
                $out[] = $common + [
                    'purchase_order_line_id' => $l['purchase_order_line_id'] ?? null,
                    $qtyField                => round($base, 3),
                    'base_quantity'          => round($base, 3),
                    'unit_cost'              => round($net, 4),
                    'discount_amount'        => 0,
                    'tax_amount'             => 0,
                    'notes'                  => self::note($p, $packs, $l, $buy, $priceRaw, $discRaw),
                ];
            }
            if ($free > 0) {
                $out[] = $common + [
                    'purchase_order_line_id' => null,
                    $qtyField                => round($free, 3),
                    'base_quantity'          => round($free, 3),
                    'unit_cost'              => 0,
                    'discount_amount'        => 0,
                    'tax_amount'             => 0,
                    'notes'                  => 'Bonus (free): ' . self::trim($freeRaw) . ' ' . $buy['name'],
                ];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /**
     * Packs of a product keyed for the page: real pack ids, plus "base" for the product's own unit when it
     * has no pack rows (or packs are off).
     *
     * @return array<string, array{id:?int,name:string,factor:int,can_buy:bool}>
     */
    public static function packsOf(Product $p, bool $packsOn): array
    {
        $baseName = $p->unit?->name ?: 'Piece';
        if ($packsOn && $p->packUnits->isNotEmpty()) {
            $out = [];
            foreach ($p->packUnits->sortByDesc('factor') as $u) {
                $out[(string) $u->id] = ['id' => $u->id, 'name' => $u->factor === 1 ? ($u->name ?: $baseName) : $u->name, 'factor' => $u->factor, 'can_buy' => (bool) $u->can_buy];
            }

            return $out;
        }

        return ['base' => ['id' => null, 'name' => $baseName, 'factor' => 1, 'can_buy' => true]];
    }

    /** @param array<string,array{id:?int,name:string,factor:int,can_buy:bool}> $packs */
    private static function biggestBuyable(array $packs): array
    {
        foreach ($packs as $p) {   // biggest first
            if ($p['can_buy']) {
                return $p;
            }
        }

        return reset($packs);
    }

    /** "09/28", "0928", "09-28", "9/28", "09/2028" → 2028-09-30; "2028-09-30" stays; '' → null; anything else → false. */
    public static function expiry($raw): string|false|null
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) && strtotime($raw)) {
            return $raw;
        }
        if (preg_match('/^(\d{1,2})\s*[\/\-.\s]?\s*(\d{2}|\d{4})$/', $raw, $m)) {
            $month = (int) $m[1];
            $year = strlen($m[2]) === 2 ? 2000 + (int) $m[2] : (int) $m[2];
            if ($month < 1 || $month > 12) {
                return false;
            }

            return Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        }

        return false;
    }

    /** What the line said, kept on it: "2 Box 3 Strip @ 892 / Box, −5%". */
    private static function note(Product $p, array $packs, array $l, array $buy, string $price, string $disc): string
    {
        $parts = [];
        foreach ($packs as $key => $pk) {
            $v = trim((string) (($l['packs'] ?? [])[$key] ?? ''));
            if ($v !== '' && (float) $v > 0) {
                $parts[] = self::trim($v) . ' ' . $pk['name'];
            }
        }
        $note = implode(' ', $parts) . ' @ ' . self::trim($price) . ' / ' . $buy['name'];
        if ($disc !== '' && (float) $disc > 0) {
            $note .= ', −' . self::trim($disc) . '%';
        }

        return mb_substr($note, 0, 500);
    }

    private static function trim(string $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
    }
}
