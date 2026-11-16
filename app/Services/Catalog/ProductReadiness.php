<?php

namespace App\Services\Catalog;

use App\Models\Tenant\Unit;
use App\Support\StandardUnits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UB-10 (unified-business roadmap, W2) — which items the new entry screens (purchase in packs, the retail counter)
 * can take as they are. READ-ONLY: it changes nothing and fixes nothing — the owner's rule is that old entries, units
 * and quantities are never relabelled or converted by this work. An item that is not ready keeps its old screens.
 *
 *   ready    nothing in its setup or history stops the new screens
 *   limited  the new screens work, but not for everything it did before (the reason says what)
 *   blocked  the new screens cannot take it until its setup changes
 *
 * Runs on the current tenant connection. Totals come from grouped queries, so a 2,000-item tenant reads fast.
 */
final class ProductReadiness
{
    public const READY = 'ready';

    public const LIMITED = 'limited';

    public const BLOCKED = 'blocked';

    /** Stock units that are containers: a single piece of what is inside cannot be sold from them. */
    private const CONTAINERS = ['CTN', 'CARTON', 'SACK', 'CRATE', 'CRT', 'BAG'];

    /**
     * @return array{checked:int, summary:array{ready:int, limited:int, blocked:int},
     *   items: list<array{id:int, name:string, unit:string, status:string, reasons:list<string>}>}
     *   items = only the ones that are not ready, blocked first
     */
    public function forTenant(): array
    {
        $c = DB::connection('tenant');
        $s = Schema::connection('tenant');
        $units = Unit::query()->get()->keyBy('id');
        $products = $c->table('products')
            ->where(fn ($q) => $q->where('is_stock_tracked', 1)->orWhere('is_purchasable', 1))
            ->orderBy('name')->get(['id', 'name', 'unit_id']);

        $partUnitIds = $units->filter(fn (Unit $u) => StandardUnits::allowsParts($u))->keys()->all() ?: [0];
        $fraction = 'ABS(%1$s - ROUND(%1$s)) > 0.0000001';

        // Part quantities typed on items kept in a count unit (or no unit): the new purchase screen refuses them.
        $grnParts = $c->table('goods_receipt_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')
            ->whereRaw(sprintf($fraction, 'l.quantity_received'))
            ->where(fn ($q) => $q->whereNull('p.unit_id')->orWhereNotIn('p.unit_id', $partUnitIds))
            ->groupBy('l.product_id')->selectRaw('l.product_id, COUNT(*) n, MAX(l.quantity_received) eg')
            ->get()->keyBy('product_id');
        $stockParts = $c->table('stock_balances as b')->join('products as p', 'p.id', '=', 'b.product_id')
            ->whereRaw(sprintf($fraction, 'b.quantity_on_hand'))
            ->where(fn ($q) => $q->whereNull('p.unit_id')->orWhereNotIn('p.unit_id', $partUnitIds))
            ->groupBy('b.product_id')->selectRaw('b.product_id, MAX(b.quantity_on_hand) eg')
            ->get()->keyBy('product_id');

        // Recipe lines in a unit the item is not kept in, with no conversion row and no standard pair: such a sale is refused.
        $noPath = [];
        if ($s->hasTable('recipe_ingredients')) {
            $conversions = $s->hasTable('unit_conversions')
                ? $c->table('unit_conversions')->get(['from_unit_id', 'to_unit_id'])->map(fn ($r) => $r->from_unit_id . '-' . $r->to_unit_id)->flip()
                : collect();
            foreach ($c->table('recipe_ingredients as i')->join('products as p', 'p.id', '=', 'i.product_id')
                ->whereNotNull('i.unit_id')->whereNotNull('p.unit_id')->whereColumn('i.unit_id', '!=', 'p.unit_id')
                ->get(['i.product_id', 'i.unit_id', 'p.unit_id as stock_unit_id']) as $r) {
                $known = $conversions->has($r->unit_id . '-' . $r->stock_unit_id) || $conversions->has($r->stock_unit_id . '-' . $r->unit_id)
                    || StandardUnits::factor($units[$r->unit_id] ?? null, $units[$r->stock_unit_id] ?? null) !== null;
                if (! $known) {
                    $noPath[$r->product_id][] = ($units[$r->unit_id]->code ?? '?') . ' → ' . ($units[$r->stock_unit_id]->code ?? '?');
                }
            }
        }

        $summary = [self::READY => 0, self::LIMITED => 0, self::BLOCKED => 0];
        $items = [];
        foreach ($products as $p) {
            $unit = $p->unit_id ? ($units[$p->unit_id] ?? null) : null;
            $reasons = [];
            $status = self::READY;

            if (! $unit) {
                $status = self::BLOCKED;
                $reasons[] = 'No unit — the new screens cannot tell whole numbers from part quantities. Its old screens keep working.';
            }
            if (isset($grnParts[$p->id]) || isset($stockParts[$p->id])) {
                $status = $status === self::BLOCKED ? $status : self::LIMITED;
                $eg = rtrim(rtrim(number_format((float) ($grnParts[$p->id]->eg ?? $stockParts[$p->id]->eg), 3, '.', ''), '0'), '.');
                $reasons[] = 'Bought or counted in part quantities (like ' . $eg . ') while its unit counts whole '
                    . ($unit ? $unit->name : 'items') . ' — the new purchase screen takes only whole numbers for it. '
                    . 'Keep the old screen for it; changing its unit is a separate decision.';
            }
            if ($unit && $unit->unit_type === 'quantity' && in_array(strtoupper(trim((string) $unit->code)), self::CONTAINERS, true)) {
                $status = $status === self::BLOCKED ? $status : self::LIMITED;
                $reasons[] = 'Stock is counted in ' . $unit->name . ' — a single piece of it cannot be sold on the counter.';
            }
            if (isset($noPath[$p->id])) {
                $status = $status === self::BLOCKED ? $status : self::LIMITED;
                $reasons[] = 'A recipe uses it in ' . implode(', ', array_unique($noPath[$p->id])) . ' with no conversion — that sale is refused today.';
            }

            $summary[$status]++;
            if ($status !== self::READY) {
                $items[] = ['id' => (int) $p->id, 'name' => (string) $p->name, 'unit' => $unit?->name ?? '—', 'status' => $status, 'reasons' => $reasons];
            }
        }
        usort($items, fn ($a, $b) => [$a['status'] !== self::BLOCKED, $a['name']] <=> [$b['status'] !== self::BLOCKED, $b['name']]);

        return ['checked' => $products->count(), 'summary' => $summary, 'items' => $items];
    }
}
