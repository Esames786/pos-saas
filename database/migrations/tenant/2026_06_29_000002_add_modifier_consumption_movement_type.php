<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MODIFIER-INVENTORY-1 — allow stock_ledgers.movement_type = 'modifier_consumption'.
 * The column is an ENUM; we read the current value list and append the new value
 * (idempotent, nullability preserved). Additive only — no existing rows change.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (! Schema::connection('tenant')->hasColumn('stock_ledgers', 'movement_type')) {
            return;
        }

        $col = DB::connection('tenant')->selectOne(
            "SHOW COLUMNS FROM stock_ledgers WHERE Field = 'movement_type'"
        );

        if (! $col || stripos($col->Type, 'modifier_consumption') !== false) {
            return; // already present
        }

        // $col->Type looks like: enum('a','b',...)
        $inner = substr($col->Type, 5, -1);
        $null  = (isset($col->Null) && $col->Null === 'YES') ? 'NULL' : 'NOT NULL';

        DB::connection('tenant')->statement(
            "ALTER TABLE stock_ledgers MODIFY COLUMN movement_type ENUM({$inner}, 'modifier_consumption') {$null}"
        );
    }

    public function down(): void
    {
        // Leave the enum value in place — removing it could truncate existing rows.
    }
};
