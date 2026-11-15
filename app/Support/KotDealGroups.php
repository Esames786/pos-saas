<?php

namespace App\Support;

/**
 * KOT-DEAL-HEADING-1 — a KOT ticket's deal items grouped under ONE deal heading.
 *
 * With the KOT layout switch "Deal name on top, its items under it" ON, a station's slip prints the
 * deal name ONCE and that deal's items for this station beneath it, instead of every item on its own
 * boxed row with "(Deal name)" repeated under each one (COMBO-KOT-DEAL-NAME-1, still the default).
 *
 * Routing is untouched: the ticket's lines are exactly the ones the category routing put on it, so a
 * deal whose Paratha sits in another category still prints its Paratha on that category's own slip,
 * under the same deal name. This class only decides the ORDER and the GROUPS inside one ticket; the
 * thermal builder (EscPosPayloadService::kot) and the browser KOT (kot.blade.php) both draw from it,
 * so paper and preview cannot drift apart.
 */
final class KotDealGroups
{
    /**
     * @param  iterable  $lines       the ticket's lines in print order (SalesOrderLine models, or the
     *                                plain snapshot objects a cancel KOT carries)
     * @param  iterable  $saleLines   every line of the sale, to find each deal's header line
     * @param  iterable|array  $comboNames  combo id => deal name, the fallback when no header is found
     * @param  callable  $qtyOf       fn ($line): float — the quantity THIS ticket prints for a line
     * @return array<int, array{kind: string, line?: object, name?: string, qty?: float|null, lines?: array<int, object>}>
     *               'item' entries carry the line; 'deal' entries carry the deal name, how many deals this
     *               ticket is for (null when it cannot be worked out) and the deal's lines on this ticket
     */
    public static function build(iterable $lines, iterable $saleLines, $comboNames, callable $qtyOf): array
    {
        $byId = [];
        $headersByCombo = [];
        foreach ($saleLines as $saleLine) {
            if (isset($saleLine->id)) {
                $byId[(int) $saleLine->id] = $saleLine;
            }
            if (($saleLine->line_kind ?? 'standard') === 'combo_header' && ! empty($saleLine->combo_id)) {
                $headersByCombo[(int) $saleLine->combo_id][] = $saleLine;
            }
        }
        $names = collect($comboNames)->all();

        $entries = [];
        $slot = [];   // group key => index in $entries
        foreach ($lines as $line) {
            $kind = $line->line_kind ?? 'standard';
            if ($kind === 'combo_header' || $qtyOf($line) <= 0) {
                continue;
            }

            $comboId = (int) ($line->combo_id ?? 0);
            if ($kind !== 'component' && $comboId === 0) {
                $entries[] = ['kind' => 'item', 'line' => $line];

                continue;
            }

            // A live line knows its header; a cancel snapshot carries only the combo id, so it falls
            // back to that combo's header when the sale has exactly one of it.
            $live = $byId[(int) ($line->line_id ?? $line->id ?? 0)] ?? null;
            $parentId = (int) ($line->parent_sales_order_line_id ?? $live?->parent_sales_order_line_id ?? 0);
            $header = $parentId ? ($byId[$parentId] ?? null) : null;
            if (! $header && $comboId && count($headersByCombo[$comboId] ?? []) === 1) {
                $header = $headersByCombo[$comboId][0];
            }

            // Saving a held bill recreates every line with a new id, so a ticket's own line may no longer
            // exist — the same item under the same deal is its live twin, for counting the deals.
            if ($header && ! $live) {
                foreach ($saleLines as $candidate) {
                    if ((int) ($candidate->parent_sales_order_line_id ?? 0) === (int) $header->id
                        && (int) ($candidate->product_id ?? 0) === (int) ($line->product_id ?? -1)) {
                        $live = $candidate;
                        break;
                    }
                }
            }

            $key = $header ? 'h' . (int) $header->id : 'c' . $comboId;
            if (! isset($slot[$key])) {
                $name = trim((string) ($header->product_name ?? ''));
                $slot[$key] = count($entries);
                $entries[] = [
                    'kind' => 'deal',
                    'name' => $name !== '' ? $name : (string) ($names[$comboId] ?? 'Deal'),
                    'qty' => self::dealCount($line, $live, $header, $qtyOf, $comboId),
                    'lines' => [],
                ];
            }
            $entries[$slot[$key]]['lines'][] = $line;
        }

        return $entries;
    }

    /**
     * How many deals this ticket is for, read off its first item: the item's quantity on this ticket
     * divided by that item's share of ONE deal. "2 x Platter" after a second platter is added, "1"
     * on an addition KOT that added one more. The item's share comes from the sale (its quantity over
     * the deal line's); when the sale no longer has that deal — a reprint of a ticket whose deal was
     * later removed — from the deal's own recipe. Null when it does not come out a whole number: the
     * heading then prints without a count rather than a wrong one.
     */
    private static function dealCount(object $line, ?object $live, ?object $header, callable $qtyOf, int $comboId): ?float
    {
        $deals = (float) ($header->quantity ?? 0);
        $itemTotal = (float) ($live->quantity ?? 0);
        $perDeal = $deals > 0 && $itemTotal > 0
            ? $itemTotal / $deals
            : self::recipeQuantity($comboId, (int) ($line->product_id ?? 0));
        if (! $perDeal || $perDeal <= 0) {
            return null;
        }

        $count = $qtyOf($line) / $perDeal;
        $whole = round($count);

        return $whole >= 1 && abs($count - $whole) < 0.001 ? $whole : null;
    }

    /** One deal's quantity of a product, from the deal's recipe; null when it cannot be read. */
    private static function recipeQuantity(int $comboId, int $productId): ?float
    {
        if ($comboId <= 0 || $productId <= 0) {
            return null;
        }

        try {
            $rows = \Illuminate\Support\Facades\DB::connection('tenant')->table('combo_components')
                ->where('combo_id', $comboId)->where('product_id', $productId)->pluck('quantity');
        } catch (\Throwable) {
            return null;   // payload-only rendering with no tenant database bound
        }

        // The same product twice in one recipe is ambiguous — no count beats a wrong one.
        return $rows->count() === 1 ? (float) $rows->first() : null;
    }
}
