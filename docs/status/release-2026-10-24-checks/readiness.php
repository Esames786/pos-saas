<?php
// READ-ONLY activation readiness, per tenant: business, plan modules, switches, and the facts each new switch depends on.
//  - whole_counted_stock: counted items (unit type not weight / volume / length) with a fractional quantity on any
//    purchasing or stock line, or a fractional stock balance → the business buys / keeps counted items in parts.
//  - packs: product_pack_units rows; pharmacy / mart business.
//  - purchase workspace: purchasing module; purchasing documents in the last 30 days.
use App\Models\Master\Tenant;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Support\Facades\DB;

$lineTables = ['purchase_order_lines', 'goods_receipt_lines', 'purchase_bill_lines', 'purchase_return_lines',
    'stock_adjustment_lines', 'stock_transfer_lines', 'stock_count_lines', 'stock_take_lines', 'department_transfer_lines'];
foreach (Tenant::with('subscription.plan.enabledModules')->orderBy('tenant_code')->get() as $t) {
    $mods = $t->subscription?->plan?->enabledModules?->pluck('key')->sort()->values()->all() ?? [];
    $tm = app(TenancyManager::class);
    try {
        $tm->activate($t);
        $c = DB::connection('tenant'); $db = $c->getDatabaseName(); $s = $c->getSchemaBuilder();
        $counted = "(u.unit_type IS NULL OR u.unit_type NOT IN ('weight','volume','length'))";
        $frac = [];
        foreach ($lineTables as $tb) {
            if (! $s->hasTable($tb) || ! $s->hasColumn($tb, 'product_id')) { continue; }
            $qcols = collect($c->select("SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND DATA_TYPE='decimal' AND (COLUMN_NAME LIKE '%quantity%' OR COLUMN_NAME LIKE 'qty%' OR COLUMN_NAME LIKE '%_qty')", [$db, $tb]))->pluck('c');
            foreach ($qcols as $col) {
                $n = $c->table("{$tb} as l")->join('products as p', 'p.id', '=', 'l.product_id')->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
                    ->whereRaw($counted)->whereRaw("l.`{$col}` <> FLOOR(l.`{$col}`)")->count();
                if ($n) { $frac[] = "{$tb}.{$col}={$n}"; }
            }
        }
        $fracBal = $c->table('stock_balances as b')->join('products as p', 'p.id', '=', 'b.product_id')->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->whereRaw($counted)->whereRaw('b.quantity_on_hand <> FLOOR(b.quantity_on_hand)')->count();
        $since = now()->subDays(30);
        $docs = collect(['purchase_orders', 'goods_receipts', 'purchase_bills', 'supplier_payments', 'purchase_returns'])
            ->filter(fn ($tb) => $s->hasTable($tb))->map(fn ($tb) => $tb . '=' . $c->table($tb)->where('created_at', '>=', $since)->count())->implode(' ');
        $roles = $c->table('roles as r')->leftJoin('model_has_roles as m', 'm.role_id', '=', 'r.id')->where('r.guard_name', 'tenant')
            ->groupBy('r.id', 'r.name')->selectRaw('r.name, COUNT(m.model_id) n')->get()->map(fn ($r) => "{$r->name}:{$r->n}")->implode(', ');
        $count = fn ($tb) => $s->hasTable($tb) ? $c->table($tb)->count() : '-';
        printf("== %s  business=%s  plan=%s\n   modules: %s\n   switches: %s\n   products=%s pack_units=%s recipes=%s boms=%s catering_profiles=%s\n   purchasing last 30 days: %s\n   counted items with parts: lines[%s] balances=%d\n   roles(users): %s\n",
            $t->tenant_code, $t->primary_business ?? '-', $t->subscription?->plan?->code ?? '-', implode(',', $mods), json_encode($t->features ?: new stdClass),
            $count('products'), $count('product_pack_units'), $count('recipes'), $count('manufacturing_boms'), $count('catering_product_profiles'),
            $docs, $frac ? implode(' ', $frac) : 'none', $fracBal, $roles);
    } catch (\Throwable $e) {
        printf("== %s ERROR %s\n", $t->tenant_code, substr($e->getMessage(), 0, 160));
    } finally { $tm->deactivate(); DB::setDefaultConnection('master'); }
}
