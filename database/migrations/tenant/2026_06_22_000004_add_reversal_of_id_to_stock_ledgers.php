<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-FIN-B — stock-ledger reversal linkage.
 *
 * INFRASTRUCTURE ONLY. A FUTURE reversal movement will point at the original
 * stock_ledgers row via reversal_of_id. This migration only adds the (nullable)
 * column + index — it creates NO stock-ledger rows and changes no existing
 * inventory behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasColumn('stock_ledgers', 'reversal_of_id')) {
            return;
        }
        Schema::connection('tenant')->table('stock_ledgers', function (Blueprint $t) {
            $t->unsignedBigInteger('reversal_of_id')->nullable();
            $t->index('reversal_of_id', 'stock_ledgers_reversal_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasColumn('stock_ledgers', 'reversal_of_id')) {
            return;
        }
        Schema::connection('tenant')->table('stock_ledgers', function (Blueprint $t) {
            $t->dropIndex('stock_ledgers_reversal_idx');
            $t->dropColumn('reversal_of_id');
        });
    }
};
