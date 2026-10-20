<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RX-BRANCH-SETTING-1 — does this branch's counter ask for the prescription?
 *
 * Owner, 20 Oct: the Doctor / Patient / "Seen" box (shown when a medicine marked "needs prescription" is in the
 * cart) should be a branch setting for pharmacy tenants — switched off, the counter does not ask.
 *
 *   ask_prescription  1 = ask (today's behaviour on the medical counter, and the default), 0 = do not ask
 *
 * Only the medical-store counter reads it; every other counter never showed the box. Additive, default 1.
 * A tiny table; MySQL: ALGORITHM=INSTANT, lock_wait_timeout 10. Shipped before the code that reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branches') || Schema::hasColumn('branches', 'ask_prescription')) {
            return;
        }
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET SESSION lock_wait_timeout = 10');
            DB::statement('ALTER TABLE `branches` ADD COLUMN `ask_prescription` TINYINT(1) NOT NULL DEFAULT 1, ALGORITHM=INSTANT');

            return;
        }
        Schema::table('branches', fn (Blueprint $t) => $t->boolean('ask_prescription')->default(true));
    }

    public function down(): void
    {
        if (Schema::hasColumn('branches', 'ask_prescription')) {
            Schema::table('branches', fn (Blueprint $t) => $t->dropColumn('ask_prescription'));
        }
    }
};
