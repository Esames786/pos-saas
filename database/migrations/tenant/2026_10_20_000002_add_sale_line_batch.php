<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MEDICAL-COUNTER-1 — the batch a sale line was sold from, so the bill can say "B:PN2410-07 EXP:09/28" and a
 * return can put the tablets back into that batch.
 *
 *   inventory_batch_id  the (first) batch the line's stock left from (no FK: a busy table)
 *   batch_note          what the bill prints: "PN2410-07 09/28", or "PN2410-07 09/28 ×7, PN2411-01 11/28 ×3"
 *
 * Additive, NULL on every existing line, written only by the medical-store counter. MySQL: ALGORITHM=INSTANT
 * with lock_wait_timeout 10 — no table copy, never a long lock on the live counter's busiest table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_order_lines') || Schema::hasColumn('sales_order_lines', 'batch_note')) {
            return;
        }
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET SESSION lock_wait_timeout = 10');
            DB::statement('ALTER TABLE `sales_order_lines` ADD COLUMN `inventory_batch_id` BIGINT UNSIGNED NULL, ADD COLUMN `batch_note` VARCHAR(160) NULL, ALGORITHM=INSTANT');

            return;
        }
        Schema::table('sales_order_lines', function (Blueprint $t) {
            $t->unsignedBigInteger('inventory_batch_id')->nullable();
            $t->string('batch_note', 160)->nullable();
        });
    }

    public function down(): void
    {
        $have = array_values(array_filter(['inventory_batch_id', 'batch_note'], fn ($c) => Schema::hasColumn('sales_order_lines', $c)));
        if ($have) {
            Schema::table('sales_order_lines', fn (Blueprint $t) => $t->dropColumn($have));
        }
    }
};
