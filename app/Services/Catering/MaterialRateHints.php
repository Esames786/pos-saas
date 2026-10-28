<?php

namespace App\Services\Catering;

use App\Http\Controllers\Tenant\Catering\CateringCommercialRateController;
use App\Models\Tenant\Unit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CATERING-SIMPLIFY-1 (owner, 27 Oct) — what the two rate pop-ups fill in or show when a material is chosen. Both only
 * read; nothing here writes a rate.
 *  - unitDefaults(): the Unit box. "ye unit auto select nahi hona chahiye tha?" — it is now: the book's own last rate,
 *    else the unit the material's dishes measure it in, else the material's own unit.
 *  - lastPurchases(): the latest price paid for each material, from any supplier — shown beside the cost rate as a
 *    guide, because the cost rate is in practice the purchase price and nothing showed it.
 */
final class MaterialRateHints
{
    /**
     * Material id => unit id, for every material that has an answer. Only active units: the box offers only those.
     *
     * @param  Collection  $materials          products with id and unit_id
     * @param  array       $historyByMaterial  this book's rates per material, newest first (the pop-up's history)
     * @return array<int, int>
     */
    public function unitDefaults(Collection $materials, array $historyByMaterial): array
    {
        $active = Unit::where('is_active', true)->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
        $fromBlocks = $this->blockUnits();

        $out = [];
        foreach ($materials as $m) {
            $choices = [
                $historyByMaterial[$m->id][0]['unit_id'] ?? null,
                $fromBlocks[$m->id] ?? null,
                $m->unit_id,
            ];
            foreach ($choices as $unitId) {
                if ($unitId && isset($active[(int) $unitId])) {
                    $out[(int) $m->id] = (int) $unitId;
                    break;
                }
            }
        }

        return $out;
    }

    /** Material id => the unit most of its active cost blocks use; a tie goes to the most recent block. */
    private function blockUnits(): array
    {
        return DB::connection('tenant')->table('catering_product_cost_blocks')
            ->where('is_active', true)->whereNotNull('material_product_id')->whereNotNull('unit_id')
            ->groupBy('material_product_id', 'unit_id')
            ->selectRaw('material_product_id, unit_id, COUNT(*) as n, MAX(id) as latest')
            ->orderByDesc('n')->orderByDesc('latest')
            ->get()
            ->unique('material_product_id')
            ->mapWithKeys(fn ($r) => [(int) $r->material_product_id => (int) $r->unit_id])
            ->all();
    }

    /**
     * Material id => its latest purchase: the newest POSTED goods receipt line (receipt date, then line), from any
     * supplier, with a price. unit_cost is per the item's own (base) unit — the same figure the purchase screen shows
     * as the last price. Voided receipts and zero-price lines are not purchases. Only materials ever bought come back.
     *
     * @return array<int, array{unit_cost: float, unit_id: ?int, unit: ?string, date: string, supplier: ?string, grn_no: ?string}>
     */
    public function lastPurchases(): array
    {
        $c = DB::connection('tenant');
        $ranked = $c->table('goods_receipt_lines as l')
            ->join('goods_receipts as g', 'g.id', '=', 'l.goods_receipt_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'g.supplier_id')
            ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->where('g.status', 'posted')->whereNull('g.voided_at')
            ->where('l.unit_cost', '>', 0)
            ->whereIn('p.product_kind', CateringCommercialRateController::MATERIAL_KINDS)
            ->selectRaw('l.product_id, l.unit_cost, g.receipt_date, g.grn_no, s.name as supplier, p.unit_id, u.code as unit,'
                . ' ROW_NUMBER() OVER (PARTITION BY l.product_id ORDER BY g.receipt_date DESC, l.id DESC) as rn');

        return $c->query()->fromSub($ranked, 'x')->where('rn', 1)->get()
            ->mapWithKeys(fn ($r) => [(int) $r->product_id => [
                'unit_cost' => round((float) $r->unit_cost, 4),
                'unit_id'   => $r->unit_id ? (int) $r->unit_id : null,
                'unit'      => $r->unit,
                'date'      => substr((string) $r->receipt_date, 0, 10),
                'supplier'  => $r->supplier,
                'grn_no'    => $r->grn_no,
            ]])
            ->all();
    }
}
