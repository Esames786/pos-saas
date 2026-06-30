<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-FIN-E — add 'manufacturing_fg_receipt' and 'manufacturing_fg_receipt_reversal'
 * to the stock_ledgers.movement_type enum so FG posting can create stock movements.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('stock_ledgers')) {
            return;
        }

        $col = DB::connection('tenant')->selectOne(
            "SHOW COLUMNS FROM stock_ledgers WHERE Field = 'movement_type'"
        );

        if (! $col) {
            return;
        }

        $current = $col->Type ?? '';

        $toAdd = ['manufacturing_fg_receipt', 'manufacturing_fg_receipt_reversal'];
        $alreadyAll = true;
        foreach ($toAdd as $v) {
            if (! str_contains($current, $v)) {
                $alreadyAll = false;
                break;
            }
        }
        if ($alreadyAll) {
            return;
        }

        // Extract the inner enum string and append new values.
        preg_match("/enum\((.+)\)/i", $current, $m);
        $inner = $m[1] ?? $current;

        foreach ($toAdd as $v) {
            if (! str_contains($inner, "'$v'")) {
                $inner .= ",'$v'";
            }
        }

        $null = (isset($col->Null) && $col->Null === 'YES') ? 'NULL' : 'NOT NULL';

        DB::connection('tenant')->statement(
            "ALTER TABLE stock_ledgers MODIFY COLUMN movement_type ENUM({$inner}) {$null}"
        );
    }

    public function down(): void
    {
        // No safe rollback if rows exist with these types — skip.
    }
};
