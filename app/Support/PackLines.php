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
     * @param  int|null  $branchId  the GRN's branch: a batch already known there keeps its one expiry
     * @param  array{type?:?string, value?:mixed}|null  $billDisc  a discount on the whole bill, % or Rs —
     *         spread over the paid lines by value, into their cost (the supplier's bill reads quantity × cost)
     * @return list<array<string,mixed>>  lines in the existing request shape
     */
    public static function toLines(array $lines, string $qtyField, bool $isGrn, ?int $branchId = null, ?array $billDisc = null): array
    {
        $lines = array_values(array_filter($lines, fn ($l) => is_array($l) && ! empty($l['product_id'])));
        $products = Product::with(['unit', 'packUnits', 'defaultVariant', 'purchaseUnit'])->whereIn('id', array_map(fn ($l) => (int) $l['product_id'], $lines))->get()->keyBy('id');
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
                if (($typed = self::exact($raw, null, "{$name} ({$pack['name']})", "lines.$i.packs", false)) === null) {
                    $errors["lines.$i.packs"] = self::$lastRefusal;
                    continue 2;
                }
                $base += $typed * $pack['factor'];
            }
            // W2-C: the quantity the stock will hold, exact once (a 4th decimal refused; a counted item's base — a pack
            // of 12.5 — whole where the business switched that on). Price and cost below are worked out from it.
            if ($base > 0 && ($base = self::exact($base, $p->unit, $name, "lines.$i.packs")) === null) {
                $errors["lines.$i.packs"] = self::$lastRefusal;
                continue;
            }

            // Price: of ONE buying pack. The line discount is a % or Rs off the whole line.
            $buy = $packs[(string) ($l['buy_pack'] ?? '')] ?? self::biggestBuyable($packs);
            $priceRaw = str_replace(',', '', trim((string) ($l['price'] ?? '0')));
            $discRaw = str_replace(',', '', trim((string) ($l['disc'] ?? '0')));
            $discType = ($l['disc_type'] ?? 'percent') === 'amount' ? 'amount' : 'percent';
            if (! is_numeric($priceRaw) || (float) $priceRaw < 0) {
                $errors["lines.$i.price"] = "{$name}: the price must be a number.";
                continue;
            }
            if ($discRaw !== '' && (! is_numeric($discRaw) || (float) $discRaw < 0 || ($discType === 'percent' && (float) $discRaw > 100))) {
                $errors["lines.$i.disc"] = $discType === 'percent' ? "{$name}: the discount is a percent from 0 to 100." : "{$name}: the discount must be an amount in Rs.";
                continue;
            }
            $gross = $base / $buy['factor'] * (float) $priceRaw;
            $lineDisc = $discType === 'percent' ? $gross * (float) ($discRaw ?: 0) / 100 : (float) ($discRaw ?: 0);
            if ($lineDisc > $gross + 0.005) {
                $errors["lines.$i.disc"] = "{$name}: the discount is more than the line (Rs " . number_format($gross, 2) . ').';
                continue;
            }
            $net = $base > 0 ? ($gross - $lineDisc) / $base : 0.0;

            // The scheme ("10+1": every 10 bought, 1 free) — on a GRN it fills the free quantity when none is typed.
            $schemeRaw = preg_replace('/\s+/', '', (string) ($l['scheme'] ?? ''));
            $scheme = null;
            if ($schemeRaw !== '') {
                if (! preg_match('/^(\d+)\+(\d+)$/', $schemeRaw, $sm) || (int) $sm[1] <= 0) {
                    $errors["lines.$i.scheme"] = "{$name}: write the scheme like 10+1 (buy 10, get 1 free).";
                    continue;
                }
                $scheme = [(int) $sm[1], (int) $sm[2]];
            }

            // Free (bonus) packs, GRN only.
            $freeRaw = trim((string) ($l['free'] ?? ''));
            if ($isGrn && $freeRaw === '' && $scheme && $base > 0) {
                $freeRaw = (string) (floor($base / $buy['factor'] / $scheme[0] + 1e-9) * $scheme[1]);
                if ((float) $freeRaw <= 0) {
                    $freeRaw = '';
                }
            }
            $free = 0.0;
            if ($isGrn && $freeRaw !== '') {
                if (! is_numeric($freeRaw) || (float) $freeRaw < 0 || (! $part && floor((float) $freeRaw) != (float) $freeRaw)) {
                    $errors["lines.$i.free"] = "{$name}: the free quantity must be a whole number of {$buy['name']}.";
                    continue;
                }
                if (($free = self::exact((float) $freeRaw * $buy['factor'], $p->unit, $name . ' (free)', "lines.$i.free")) === null) {
                    $errors["lines.$i.free"] = self::$lastRefusal;
                    continue;
                }
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
                // PURCHASING-V2-2: a batch keeps ONE expiry. The stock engine finds a batch by its number and
                // would silently keep the old date, so a different date for a known batch is refused here.
                $batchNo = strtoupper(trim((string) ($l['batch_no'] ?? '')));
                if ($branchId && $expiry && $batchNo !== '') {
                    $known = \App\Models\Tenant\InventoryBatch::where('branch_id', $branchId)->where('product_id', $p->id)
                        ->where('batch_no', $batchNo)->whereNotNull('expiry_date')->value('expiry_date');
                    if ($known && Carbon::parse($known)->toDateString() !== $expiry) {
                        $errors["lines.$i.expiry_date"] = "{$name}: batch {$batchNo} already has expiry " . Carbon::parse($known)->format('m/y') . ' — pick it from the list, or use a new batch number.';
                        continue;
                    }
                }
            }

            $common = [
                'product_id'             => $p->id,
                // PURCHASING-V2-2: no variant sent = the item's own (default) one — the one the counter sells from and the
                // batch box / batches made beforehand use. A null variant would file the stock where nothing finds it.
                'product_variant_id'     => ($l['product_variant_id'] ?? null) ?: $p->defaultVariant?->id,
                'pack_unit_id'           => $buy['id'],
                'pack_factor'            => self::wholeOrNull($buy['factor']),
                'pack_size'              => (float) $buy['factor'],   // UNITS-PACKS: the exact size (2.5 kg)
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
                    'notes'                  => self::withNote(self::note($p, $packs, $l, $buy, $priceRaw, $discRaw, $discType, $schemeRaw), $l),
                    // PURCHASE-DISCOUNTS-1: what was typed, read back as "the last deal" with this supplier.
                    'entry_json'             => ['buy_pack' => (string) ($l['buy_pack'] ?? ''), 'pack' => $buy['name'], 'factor' => $buy['factor'], 'price' => (float) $priceRaw,
                        'disc_type' => $discType, 'disc' => (float) ($discRaw ?: 0), 'scheme' => $schemeRaw !== '' ? $schemeRaw : null, 'free' => $freeRaw !== '' ? (float) $freeRaw : null],
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

        // PURCHASE-DISCOUNTS-1: a discount on the whole bill (% or Rs), spread over the paid lines by value
        // into their cost — the supplier's bill and the stock value both come out right.
        $bd = self::billDiscount($billDisc);
        if ($bd === false) {
            $errors['bill_disc'] = 'The bill discount is a % from 0 to 100, or an amount in Rs.';
        } elseif ($bd && ! $errors) {
            $paid = array_keys(array_filter($out, fn ($o) => (float) $o['unit_cost'] > 0));
            $value = array_sum(array_map(fn ($k) => $out[$k][$qtyField] * $out[$k]['unit_cost'], $paid));
            $off = $bd['type'] === 'percent' ? $value * $bd['value'] / 100 : $bd['value'];
            if ($off > $value + 0.005) {
                $errors['bill_disc'] = 'The bill discount (Rs ' . number_format($off, 2) . ') is more than the goods (Rs ' . number_format($value, 2) . ').';
            } elseif ($off > 0 && $value > 0) {
                $word = $bd['type'] === 'percent' ? self::trim((string) $bd['value']) . '%' : 'Rs ' . self::trim((string) $bd['value']);
                foreach ($paid as $k) {
                    $lineValue = $out[$k][$qtyField] * $out[$k]['unit_cost'];
                    $out[$k]['unit_cost'] = round(($lineValue - $off * $lineValue / $value) / $out[$k][$qtyField], 4);
                    $out[$k]['notes'] = mb_substr($out[$k]['notes'] . ' · bill disc ' . $word, 0, 500);
                    $out[$k]['entry_json'] += ['bill_disc_type' => $bd['type'], 'bill_disc' => $bd['value']];
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /** W2-C: why the last exact() refused, in the counter's words. */
    private static string $lastRefusal = '';

    /**
     * W2-C: a quantity made exact to 3 decimals (LineQuantity), or null with the reason kept in $lastRefusal, so the
     * line's error joins the others on this page. With the counted rule, a counted item must also be whole where switched on.
     */
    private static function exact($value, ?\App\Models\Tenant\Unit $unit, string $label, string $field, bool $countedRule = true): ?float
    {
        try {
            return $countedRule ? LineQuantity::forStock($value, $unit, $label, $field) : LineQuantity::normalize($value, $label, $field);
        } catch (ValidationException $e) {
            self::$lastRefusal = (string) collect($e->errors())->flatten()->first();

            return null;
        }
    }

    /** @return array{type:string, value:float}|null|false  null = none, false = not a valid discount */
    private static function billDiscount(?array $in): array|null|false
    {
        $raw = str_replace(',', '', trim((string) ($in['value'] ?? '')));
        if ($raw === '' || (is_numeric($raw) && (float) $raw == 0)) {
            return null;
        }
        $type = ($in['type'] ?? 'percent') === 'amount' ? 'amount' : 'percent';
        if (! is_numeric($raw) || (float) $raw < 0 || ($type === 'percent' && (float) $raw > 100)) {
            return false;
        }

        return ['type' => $type, 'value' => (float) $raw];
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
                $out[(string) $u->id] = ['id' => $u->id, 'name' => \App\Models\Tenant\ProductPackUnit::same((float) $u->factor, 1.0) ? ($u->name ?: $baseName) : $u->name, 'factor' => $u->factor, 'can_buy' => (bool) $u->can_buy];
            }

            return $out;
        }

        // PURCHASING-V2-2 (owner: "kis unit mein purchase karein"): the product screen's "bought in — holding"
        // (e.g. a Sack holding 50 Kilogram) is a buying pack on the GRN / PO too. Until now only recipe costing
        // read it; the price is then per sack and the stock still goes in by the kilogram.
        $out = [];
        $size = (float) ($p->purchase_pack_size ?? 0);
        if ($p->purchase_unit_id && $size > 0 && abs($size - 1) > 1e-9 && $p->purchaseUnit) {
            $out['buy'] = ['id' => null, 'name' => $p->purchaseUnit->name, 'factor' => $size, 'can_buy' => true,
                'holds' => rtrim(rtrim(number_format($size, 4, '.', ''), '0'), '.') . ' ' . $baseName];
        }
        $out['base'] = ['id' => null, 'name' => $baseName, 'factor' => 1, 'can_buy' => true];

        return $out;
    }

    /** pack_factor is a whole-number column: a 12.5 kg "pack" keeps its size in pack_size (and the note). */
    public static function wholeOrNull($factor): ?int
    {
        $f = (float) $factor;

        return abs($f - round($f)) < 1e-9 ? (int) round($f) : null;
    }

    /** The person's own note on a line ("one carton torn"), after what the line said. */
    public static function withNote(string $summary, array $l): string
    {
        $note = trim(preg_replace('/\s+/u', ' ', (string) ($l['note'] ?? '')));

        return mb_substr($note !== '' ? $summary . ' · ' . $note : $summary, 0, 500);
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

    /** What the line said, kept on it: "2 Box 3 Strip @ 892 / Box, −5%, scheme 10+1". */
    private static function note(Product $p, array $packs, array $l, array $buy, string $price, string $disc, string $discType = 'percent', string $scheme = ''): string
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
            $note .= $discType === 'amount' ? ', −Rs ' . self::trim($disc) : ', −' . self::trim($disc) . '%';
        }
        if ($scheme !== '') {
            $note .= ', scheme ' . $scheme;
        }

        return mb_substr($note, 0, 500);
    }

    private static function trim(string $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
    }
}
