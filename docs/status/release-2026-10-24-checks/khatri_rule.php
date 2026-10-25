<?php
// READ-ONLY: khatribiryani — the switches as stored, and the server's answer for an item it has bought in parts. Writes nothing.
use App\Models\Master\Tenant; use App\Services\Tenancy\TenancyManager; use App\Support\LineQuantity; use App\Support\TenantFeatures; use Illuminate\Support\Facades\DB;
$t = Tenant::where('tenant_code', 'khatribiryani')->firstOrFail(); app(TenancyManager::class)->activate($t); app()->instance('tenant', $t);
echo 'whole_counted_stock on: ' . (TenantFeatures::on(TenantFeatures::WHOLE_COUNTED_STOCK, $t) ? 'YES' : 'no') . '; purchasing_v2: ' . (TenantFeatures::on(TenantFeatures::PURCHASING_V2, $t) ? 'on' : 'off') . '; purchase_workspace: ' . (TenantFeatures::on(TenantFeatures::PURCHASE_WORKSPACE, $t) ? 'on' : 'off') . "\n";
$row = DB::connection('tenant')->table('goods_receipt_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
    ->where(fn ($q) => $q->whereNull('u.unit_type')->orWhereNotIn('u.unit_type', ['weight', 'volume', 'length']))->whereRaw('l.quantity_received <> FLOOR(l.quantity_received)')
    ->orderByDesc('l.id')->first(['p.id', 'p.name', 'l.quantity_received']);
$p = \App\Models\Tenant\Product::on('tenant')->with('unit')->find($row->id);
printf("item bought in parts: #%d %s, last received %s %s\n", $p->id, $p->name, $row->quantity_received, $p->unit?->name ?? '(no unit)');
printf("its own unit takes a part now: %s; a quantity of %s passes the server check: %s\n", LineQuantity::packTakesParts($p->unit, 1.0) ? 'yes' : 'NO',
    $row->quantity_received, (function () use ($row, $p) { try { LineQuantity::forStock($row->quantity_received, $p->unit, $p->name); return 'yes'; } catch (\Throwable $e) { return 'NO — ' . $e->getMessage(); } })());
