<?php
// READ-ONLY: explain the fingerprint differences in sales_order_lines / kot_batch_lines after the 25 Oct deploy.
use App\Models\Master\Tenant; use App\Services\Tenancy\TenancyManager; use Illuminate\Support\Facades\DB;
$b = json_decode(file_get_contents('/tmp/recon_before.json'), true); $since = $b['taken_at'];
foreach (['khatribiryani', 'kashiffood'] as $code) {
    $t = Tenant::where('tenant_code', $code)->first(); app(TenancyManager::class)->activate($t); $c = DB::connection('tenant'); $db = $c->getDatabaseName();
    $cutL = $b['tenants'][$code]['tables']['sales_order_lines']['cutoff']; $cutK = $b['tenants'][$code]['tables']['kot_batch_lines']['cutoff'];
    $fk = $c->selectOne("SELECT DELETE_RULE r FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=? AND TABLE_NAME='kot_batch_lines' AND REFERENCED_TABLE_NAME='sales_order_lines'", [$db]);
    echo "== {$code}  (before snapshot {$since} UTC; old rows: sales_order_lines id<={$cutL}, kot_batch_lines id<={$cutK})\n";
    echo "   kot_batch_lines -> sales_order_lines ON DELETE " . ($fk->r ?? 'no FK') . "\n";
    $orders = $c->table('sales_orders')->where('updated_at', '>=', $since)->orderBy('id')->get(['id', 'status', 'order_type', 'updated_at']);
    foreach ($orders as $o) {
        $old = $c->table('sales_order_lines')->where('sales_order_id', $o->id)->where('id', '<=', $cutL)->count();
        $new = $c->table('sales_order_lines')->where('sales_order_id', $o->id)->where('id', '>', $cutL)->count();
        $kotNull = $c->table('kot_batch_lines as k')->join('kot_batches as b', 'b.id', '=', 'k.kot_batch_id')->where('b.sales_order_id', $o->id)
            ->where('k.id', '<=', $cutK)->whereNull('k.sales_order_line_id')->count();
        $kotRelinkable = $c->table('kot_batch_lines as k')->join('kot_batches as b', 'b.id', '=', 'k.kot_batch_id')->where('b.sales_order_id', $o->id)
            ->where('k.id', '<=', $cutK)->whereNull('k.sales_order_line_id')
            ->whereExists(fn ($q) => $q->from('sales_order_lines as l')->whereColumn('l.line_uuid', 'k.source_line_uuid')->where('l.id', '>', $cutL))->count();
        printf("   order #%d %-10s %-9s updated %s: old lines now %d, new lines %d; old KOT lines unlinked %d (of which re-saved under the same line uuid: %d)\n",
            $o->id, $o->status, $o->order_type, $o->updated_at, $old, $new, $kotNull, $kotRelinkable);
    }
    // Every old KOT line unlinked since the snapshot belongs to one of those orders?
    $allNull = $c->table('kot_batch_lines as k')->join('kot_batches as b', 'b.id', '=', 'k.kot_batch_id')->where('k.id', '<=', $cutK)->whereNull('k.sales_order_line_id')
        ->whereIn('b.sales_order_id', $orders->pluck('id'))->count();
    echo "   old KOT lines unlinked on these orders: {$allNull}; orders touched: {$orders->count()}; completed orders among them: " . $orders->where('status', 'completed')->count() . "\n";
    app(TenancyManager::class)->deactivate(); DB::setDefaultConnection('master');
}
