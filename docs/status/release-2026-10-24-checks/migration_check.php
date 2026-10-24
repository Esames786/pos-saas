<?php
// READ-ONLY. Per tenant: tenant migration files not yet recorded (pending), and the four schema changes of this release.
use App\Models\Master\Tenant;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Support\Facades\DB;

$files = collect(glob(base_path('database/migrations/tenant/*.php')))->map(fn ($f) => basename($f, '.php'));
$bad = 0;
foreach (Tenant::orderBy('tenant_code')->get() as $t) {
    $tm = app(TenancyManager::class);
    try {
        $tm->activate($t);
        $c = DB::connection('tenant'); $db = $c->getDatabaseName(); $s = $c->getSchemaBuilder();
        $ran = $c->table('migrations')->pluck('migration');
        $pending = $files->diff($ran)->values();
        $factor = $c->selectOne("SELECT COLUMN_TYPE t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='product_pack_units' AND COLUMN_NAME='factor'", [$db])->t ?? '-';
        $ppu = $c->table('product_pack_units')->selectRaw('COUNT(*) n, MIN(factor) mn, MAX(factor) mx, SUM(factor <> FLOOR(factor)) frac')->first();
        $packSize = collect(['sales_order_lines','sales_return_lines','goods_receipt_lines','purchase_order_lines','purchase_bill_lines','purchase_return_lines','stock_adjustment_lines','stock_transfer_lines'])
            ->filter(fn ($tb) => $s->hasTable($tb) && $s->hasColumn($tb, 'pack_factor'))->reject(fn ($tb) => $s->hasColumn($tb, 'pack_size'))->values();
        $ok = $pending->isEmpty() && str_starts_with($factor, 'decimal(15,4)') && $packSize->isEmpty()
            && $s->hasTable('purchase_bill_return_nettings') && $s->hasTable('supplier_credit_reallocations')
            && $s->hasColumn('sales_order_lines', 'entered_unit_code') && $s->hasColumn('goods_receipts', 'submission_key');
        if (! $ok) { $bad++; }
        printf("%-20s %s pending=%d factor=%s ppu=%d [%s..%s] frac=%d pack_size_missing=%s\n", $t->tenant_code, $ok ? 'OK ' : 'BAD', $pending->count(), $factor,
            $ppu->n, $ppu->mn ?? '-', $ppu->mx ?? '-', (int) $ppu->frac, $packSize->isEmpty() ? '-' : $packSize->implode(','));
        foreach ($pending as $p) { echo "    pending: {$p}\n"; }
    } catch (\Throwable $e) {
        $bad++; printf("%-20s ERROR %s\n", $t->tenant_code, substr($e->getMessage(), 0, 140));
    } finally { $tm->deactivate(); DB::setDefaultConnection('master'); }
}
echo $bad ? "MIGRATIONS: {$bad} tenant(s) NOT complete\n" : "MIGRATIONS COMPLETE on every tenant\n";
