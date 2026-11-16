<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UB-06 — a return form is posted once.
 *
 *   submission_key   the key the form was drawn with (unique: a second post of the same form finds the first return)
 *   submission_hash  a fingerprint of what that form asked for (the same key with other contents is refused)
 *
 * Additive, NULL on every existing return; nothing is backfilled. MySQL: the columns go in with ALGORITHM=INSTANT
 * and the unique index in place without locking writes (LOCK=NONE), lock_wait_timeout 10 — a busy counter is never
 * queued behind it. The code that writes the key checks the column first, so it is safe in the same release.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_returns') || Schema::hasColumn('sales_returns', 'submission_key')) {
            return;
        }
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET SESSION lock_wait_timeout = 10');
            DB::statement('ALTER TABLE `sales_returns` ADD COLUMN `submission_key` VARCHAR(64) NULL, ADD COLUMN `submission_hash` CHAR(64) NULL, ALGORITHM=INSTANT');
            DB::statement('ALTER TABLE `sales_returns` ADD UNIQUE INDEX `sales_returns_submission_key_unique` (`submission_key`), ALGORITHM=INPLACE, LOCK=NONE');

            return;
        }
        Schema::table('sales_returns', function (Blueprint $t) {
            $t->string('submission_key', 64)->nullable()->unique();
            $t->char('submission_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('sales_returns', 'submission_key')) {
            return;
        }
        Schema::table('sales_returns', function (Blueprint $t) {
            $t->dropUnique('sales_returns_submission_key_unique');
            $t->dropColumn(['submission_key', 'submission_hash']);
        });
    }
};
