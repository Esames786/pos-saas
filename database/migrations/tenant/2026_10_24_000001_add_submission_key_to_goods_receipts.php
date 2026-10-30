<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * W3/W4 S0 — a goods receipt is posted once. The new screen already sends a form key and tells the user "press Post
 * again" when no answer came back, but the server ignored the key: a retried post made a second receipt and doubled
 * the stock. Same two columns as on sales_returns / purchase_returns: the form's key (unique) and a fingerprint of
 * what it asked for.
 *
 * Additive, NULL on every existing receipt, nothing backfilled; INSTANT columns + an in-place unique index,
 * lock_wait_timeout 10. The code checks the column before writing it, so it can ship after this (schema first).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('goods_receipts') || Schema::hasColumn('goods_receipts', 'submission_key')) {
            return;
        }
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET SESSION lock_wait_timeout = 10');
            DB::statement('ALTER TABLE `goods_receipts` ADD COLUMN `submission_key` VARCHAR(64) NULL, ADD COLUMN `submission_hash` CHAR(64) NULL, ALGORITHM=INSTANT');
            DB::statement('ALTER TABLE `goods_receipts` ADD UNIQUE INDEX `goods_receipts_submission_key_unique` (`submission_key`), ALGORITHM=INPLACE, LOCK=NONE');

            return;
        }
        Schema::table('goods_receipts', function (Blueprint $t) {
            $t->string('submission_key', 64)->nullable()->unique();
            $t->char('submission_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('goods_receipts', 'submission_key')) {
            return;
        }
        Schema::table('goods_receipts', function (Blueprint $t) {
            $t->dropUnique('goods_receipts_submission_key_unique');
            $t->dropColumn(['submission_key', 'submission_hash']);
        });
    }
};
