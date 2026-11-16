<?php
// READ-ONLY release census on production — writes nothing. For the release candidate 7b4719c6:
//  1. MySQL version;
//  2. every tenant in master (any status) with its database, plus pos_tenant_* schemas master does not list;
//  3. each tenant's switches (tenants.features) exactly as stored;
//  4. per tenant DB: product_pack_units (rows, factor type, min/max, size) — the table the release converts;
//     the tables that get INSTANT columns: rows, size, current INSTANT row versions (limit 64), columns already there.
use Illuminate\Support\Facades\DB;

$m = DB::connection('master');
echo 'mysql ' . $m->selectOne('SELECT VERSION() v')->v . "\n";
$hasVersions = (bool) $m->selectOne("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='information_schema' AND TABLE_NAME='INNODB_TABLES' AND COLUMN_NAME='TOTAL_ROW_VERSIONS'")->c;

$tenants = $m->table('tenants as t')->leftJoin('tenant_databases as d', 'd.tenant_id', '=', 't.id')
    ->orderBy('t.tenant_code')->get(['t.id', 't.tenant_code', 't.status', 't.features', 'd.db_database', 'd.migration_status']);
echo "\n== tenants in master: " . $tenants->count() . "\n";
foreach ($tenants as $t) {
    printf("%-20s status=%-9s db=%-34s migration=%-10s features=%s\n", $t->tenant_code, $t->status, $t->db_database ?? '-', $t->migration_status ?? '-', $t->features ?: '{}');
}
$listed = $tenants->pluck('db_database')->filter()->all();
$schemas = collect($m->select("SELECT SCHEMA_NAME s FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'pos\\_tenant\\_%'"))->pluck('s');
echo "\n== pos_tenant_* schemas on the server: " . $schemas->count() . '; not listed in master: ' . json_encode($schemas->diff($listed)->values()) . "\n";

$lineTables = ['sales_order_lines', 'sales_return_lines', 'goods_receipt_lines', 'purchase_order_lines', 'purchase_bill_lines',
    'purchase_return_lines', 'stock_adjustment_lines', 'stock_transfer_lines', 'goods_receipts'];
echo "\n== per tenant database\n";
foreach ($schemas as $db) {
    $ppu = $m->selectOne("SELECT COLUMN_TYPE t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='product_pack_units' AND COLUMN_NAME='factor'", [$db]);
    $line = "-- {$db}\n   product_pack_units: ";
    if ($ppu) {
        $s = $m->selectOne("SELECT COUNT(*) n, MIN(factor) mn, MAX(factor) mx FROM `{$db}`.`product_pack_units`");
        $size = $m->selectOne("SELECT DATA_LENGTH + INDEX_LENGTH b FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME='product_pack_units'", [$db]);
        $line .= sprintf('factor=%s rows=%d min=%s max=%s size=%dKB', $ppu->t, $s->n, $s->mn ?? '-', $s->mx ?? '-', (int) ($size->b / 1024));
    } else {
        $line .= 'no table';
    }
    echo $line . "\n";
    foreach ($lineTables as $tb) {
        $info = $m->selectOne('SELECT TABLE_ROWS r, DATA_LENGTH + INDEX_LENGTH b FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', [$db, $tb]);
        if (! $info) {
            continue;
        }
        $versions = $hasVersions ? $m->selectOne('SELECT TOTAL_ROW_VERSIONS v FROM information_schema.INNODB_TABLES WHERE NAME=?', [$db . '/' . $tb])->v ?? '?' : 'n/a';
        $cols = collect($m->select("SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME IN ('pack_factor','pack_size','entered_unit_code','submission_key')", [$db, $tb]))->pluck('c')->implode(',');
        printf("   %-24s rows~%-8d size=%-7dKB row_versions=%-3s has=%s\n", $tb, $info->r, (int) ($info->b / 1024), $versions, $cols ?: '-');
    }
}
