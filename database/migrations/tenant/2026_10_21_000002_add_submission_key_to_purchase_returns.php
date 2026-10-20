<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UB-06 — a supplier return posted from the new screen ("Post return": save and post in one go) is posted once.
 * Same two columns as on sales_returns: the form's key (unique) and a fingerprint of what it asked for. Additive,
 * NULL on every existing return, nothing backfilled; INSTANT columns + an in-place unique index, lock_wait_timeout
 * 10. The code checks the column before writing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_returns') || Schema::hasColumn('purchase_returns', 'submission_key')) {
            return;
        }
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET SESSION lock_wait_timeout = 10');
            DB::statement('ALTER TABLE `purchase_returns` ADD COLUMN `submission_key` VARCHAR(64) NULL, ADD COLUMN `submission_hash` CHAR(64) NULL, ALGORITHM=INSTANT');
            DB::statement('ALTER TABLE `purchase_returns` ADD UNIQUE INDEX `purchase_returns_submission_key_unique` (`submission_key`), ALGORITHM=INPLACE, LOCK=NONE');

            return;
        }
        Schema::table('purchase_returns', function (Blueprint $t) {
            $t->string('submission_key', 64)->nullable()->unique();
            $t->char('submission_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('purchase_returns', 'submission_key')) {
            return;
        }
        Schema::table('purchase_returns', function (Blueprint $t) {
            $t->dropUnique('purchase_returns_submission_key_unique');
            $t->dropColumn(['submission_key', 'submission_hash']);
        });
    }
};
