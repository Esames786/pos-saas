<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KHATRI-STOCK-TO-PNL-1 — add 'expensed_to_cost' to stock_ledgers.movement_type: the row that writes out
 * the stock left of an item made purchase-only by inventory:expense-purchase-stock (its cost already went
 * to the category cost head on each bill date). Appended at the end, as MFG-FIN-E did — MySQL 8 changes
 * only the column's metadata, no table rebuild. Nothing writes it until someone runs that command.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('stock_ledgers')) {
            return;
        }

        $col = DB::connection('tenant')->selectOne("SHOW COLUMNS FROM stock_ledgers WHERE Field = 'movement_type'");
        if (! $col || str_contains($col->Type ?? '', "'expensed_to_cost'")) {
            return;
        }

        preg_match('/enum\((.+)\)/i', $col->Type ?? '', $m);
        if (empty($m[1])) {
            return;
        }
        $null = (isset($col->Null) && $col->Null === 'YES') ? 'NULL' : 'NOT NULL';

        DB::connection('tenant')->statement(
            "ALTER TABLE stock_ledgers MODIFY COLUMN movement_type ENUM({$m[1]},'expensed_to_cost') {$null}"
        );
    }

    public function down(): void
    {
        // No safe rollback once rows carry this type — skip, as the other movement-type migrations do.
    }
};
