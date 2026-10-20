<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RETURN-RESTOCK-1 — did the returned item go back on the shelf?
 *
 * Owner, 20 Oct: each returned line asks "Back on the shelf?" — a sealed medicine or a packed item, yes; an
 * opened strip, food from a kitchen, bread from yesterday, no (it is thrown away). Until now every return put
 * the stock back. "No" puts nothing back and keeps the cost in COGS (the goods are a loss), so the line must
 * remember which it was.
 *
 *   restocked  1 = back on the shelf (every existing line, and the default), 0 = thrown away
 *
 * Additive, defaults to 1 (today's behaviour for every existing row). MySQL: ALGORITHM=INSTANT,
 * lock_wait_timeout 10. Shipped before the code that writes it (schema first).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_return_lines') || Schema::hasColumn('sales_return_lines', 'restocked')) {
            return;
        }
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET SESSION lock_wait_timeout = 10');
            DB::statement('ALTER TABLE `sales_return_lines` ADD COLUMN `restocked` TINYINT(1) NOT NULL DEFAULT 1, ALGORITHM=INSTANT');

            return;
        }
        Schema::table('sales_return_lines', fn (Blueprint $t) => $t->boolean('restocked')->default(true));
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_return_lines', 'restocked')) {
            Schema::table('sales_return_lines', fn (Blueprint $t) => $t->dropColumn('restocked'));
        }
    }
};
