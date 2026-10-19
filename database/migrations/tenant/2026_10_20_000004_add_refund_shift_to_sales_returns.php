<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REFUND-SHIFT-1 — the shift and the counter a refund was PAID from.
 *
 * Until now a return adjusted the ORIGINAL sale's shift, and only if that shift was still open: a bill from
 * yesterday returned today reached no shift and no day closing (the cash left today's drawer, today's closing
 * looked short). From now the refund goes into the shift open at the counter that pays it, and the return
 * remembers which one, so the shift report and the day closing agree.
 *
 *   shift_id     the shift whose drawer paid the refund (null = none open — as before)
 *   terminal_id  that counter
 *
 * Additive, NULL on every existing return (reports fall back to the sale's own shift / counter for those, so
 * no past figure moves). No FK (busy reporting table). MySQL: ALGORITHM=INSTANT, lock_wait_timeout 10.
 * Shipped before the code that writes it (schema first).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_returns') || Schema::hasColumn('sales_returns', 'shift_id')) {
            return;
        }
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET SESSION lock_wait_timeout = 10');
            DB::statement('ALTER TABLE `sales_returns` ADD COLUMN `shift_id` BIGINT UNSIGNED NULL, ADD COLUMN `terminal_id` BIGINT UNSIGNED NULL, ALGORITHM=INSTANT');

            return;
        }
        Schema::table('sales_returns', function (Blueprint $t) {
            $t->unsignedBigInteger('shift_id')->nullable();
            $t->unsignedBigInteger('terminal_id')->nullable();
        });
    }

    public function down(): void
    {
        $have = array_values(array_filter(['shift_id', 'terminal_id'], fn ($c) => Schema::hasColumn('sales_returns', $c)));
        if ($have) {
            Schema::table('sales_returns', fn (Blueprint $t) => $t->dropColumn($have));
        }
    }
};
