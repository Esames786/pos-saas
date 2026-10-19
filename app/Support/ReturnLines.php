<?php

namespace App\Support;

use App\Models\Tenant\GoodsReceiptLine;
use App\Models\Tenant\InventoryBatch;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseReturn;
use App\Models\Tenant\StockBalance;
use Illuminate\Validation\ValidationException;

/**
 * PURCHASE-RETURN-V2 — the lines of the new purchase-return screen, turned into the lines the existing return
 * code saves (owner, 19 Oct: "maal box packet unit mai lere hain to return bhi usi hisab se").
 *
 * Each typed line says: the item, the batch it goes back from, how many in each pack ("2 Box 3 Strip"), the
 * price of ONE pack, and why. This turns it into today's line — quantity in the product's own unit and the
 * cost of one unit — and keeps the batch on the line, so the stock leaves THAT batch (not the oldest one).
 *
 * Rules:
 *  - A batch is required for an item kept by batch, and it must be this branch's batch of this item.
 *  - Never more than the batch holds now; packs are whole numbers (part quantities only on kg / litre / metre).
 *  - A reason on every line (or the return's own reason).
 *  - The price is the person's (it was filled from the GRN rate, the batch's cost, or the last purchase rate).
 */
final class ReturnLines
{
    /**
     * @param  array<int,array<string,mixed>>  $lines  the posted lines
     * @return list<array<string,mixed>>  lines in PurchaseReturnService's shape
     */
    public static function toLines(array $lines, int $branchId, ?string $headerReason): array
    {
        $lines = array_values(array_filter($lines, fn ($l) => is_array($l) && ! empty($l['product_id'])));
        $products = Product::with(['unit', 'packUnits', 'defaultVariant', 'purchaseUnit'])->whereIn('id', array_map(fn ($l) => (int) $l['product_id'], $lines))->get()->keyBy('id');
        $packsOn = PackUnits::enabled();
        $errors = [];
        $out = [];
        $takenFromBatch = [];

        foreach ($lines as $i => $l) {
            $p = $products[(int) $l['product_id']] ?? null;
            if (! $p) {
                $errors["lines.$i.product_id"] = 'Line ' . ($i + 1) . ': this item no longer exists.';
                continue;
            }
            $name = $p->name;
            $part = in_array($p->unit?->unit_type, ['weight', 'volume', 'length'], true);
            $packs = PackLines::packsOf($p, $packsOn);

            $base = 0.0;
            foreach ((array) ($l['packs'] ?? []) as $key => $raw) {
                $raw = trim((string) $raw);
                if ($raw === '') {
                    continue;
                }
                $pack = $packs[(string) $key] ?? null;
                if (! $pack || ! is_numeric($raw) || (float) $raw < 0 || (! $part && floor((float) $raw) != (float) $raw)) {
                    $errors["lines.$i.packs"] = "{$name}: " . ($pack ? "{$pack['name']} must be a whole number." : 'the quantity must be a number.');
                    continue 2;
                }
                $base += (float) $raw * $pack['factor'];
            }
            if ($base <= 0) {
                $errors["lines.$i.packs"] = "{$name}: how many go back? Type it in any of the boxes.";
                continue;
            }

            $buy = $packs[(string) ($l['buy_pack'] ?? '')] ?? reset($packs);
            $priceRaw = str_replace(',', '', trim((string) ($l['price'] ?? '')));
            if ($priceRaw === '' || ! is_numeric($priceRaw) || (float) $priceRaw < 0) {
                $errors["lines.$i.price"] = "{$name}: write the price of one {$buy['name']}.";
                continue;
            }

            $reason = (string) ($l['reason_code'] ?? '') ?: (string) $headerReason;
            if (! in_array($reason, PurchaseReturn::REASON_CODES, true)) {
                $errors["lines.$i.reason_code"] = "{$name}: why is it going back? Pick a reason.";
                continue;
            }

            $batchId = (int) ($l['inventory_batch_id'] ?? 0) ?: null;
            $batch = null;
            if ($batchId) {
                $batch = InventoryBatch::whereKey($batchId)->where('branch_id', $branchId)->where('product_id', $p->id)->first();
                if (! $batch) {
                    $errors["lines.$i.batch_no"] = "{$name}: that batch is not this branch's batch of this item.";
                    continue;
                }
            } elseif ($p->is_stock_tracked && ($p->requires_batch || $p->has_expiry)) {
                $errors["lines.$i.batch_no"] = "{$name}: pick the batch it goes back from.";
                continue;
            }
            if ($batch) {
                $left = (float) StockBalance::where('inventory_batch_id', $batch->id)->sum('quantity_on_hand') - ($takenFromBatch[$batch->id] ?? 0);
                if ($base > $left + 0.0005) {
                    $errors["lines.$i.packs"] = "{$name}: batch {$batch->batch_no} has only " . PackUnits::describe($packsOn ? $p->packUnits : [], max($left, 0), $p->unit?->name ?: 'Piece') . ' left.';
                    continue;
                }
                $takenFromBatch[$batch->id] = ($takenFromBatch[$batch->id] ?? 0) + $base;
            }

            $sourceId = (int) ($l['source_line_id'] ?? 0) ?: null;
            if ($sourceId && ! GoodsReceiptLine::whereKey($sourceId)->where('product_id', $p->id)->exists()) {
                $sourceId = null;
            }

            $out[] = [
                'product_id'         => $p->id,
                'product_variant_id' => ($l['product_variant_id'] ?? null) ?: $p->defaultVariant?->id,
                'source_line_id'     => $sourceId,
                'inventory_batch_id' => $batch?->id,
                'quantity'           => round($base, 3),
                'unit_cost'          => round((float) $priceRaw / $buy['factor'], 4),
                'reason_code'        => $reason,
                'pack_unit_id'       => $buy['id'],
                'pack_factor'        => PackLines::wholeOrNull($buy['factor']),
                'base_quantity'      => round($base, 3),
                'notes'              => PackLines::withNote(self::note($packs, $l, $buy, $priceRaw, $batch), $l),
            ];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /** What the line said, kept on it: "2 Box 3 Strip @ 850 / Box · B:PN2410-07 EXP:09/28". */
    private static function note(array $packs, array $l, array $buy, string $price, ?InventoryBatch $batch): string
    {
        $parts = [];
        foreach ($packs as $key => $pk) {
            $v = trim((string) (($l['packs'] ?? [])[$key] ?? ''));
            if ($v !== '' && (float) $v > 0) {
                $parts[] = rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.') . ' ' . $pk['name'];
            }
        }
        $note = implode(' ', $parts) . ' @ ' . rtrim(rtrim(number_format((float) $price, 4, '.', ''), '0'), '.') . ' / ' . $buy['name'];
        if ($batch) {
            $note .= ' · B:' . $batch->batch_no . ($batch->expiry_date ? ' EXP:' . $batch->expiry_date->format('m/y') : '');
        }

        return mb_substr($note, 0, 500);
    }
}
