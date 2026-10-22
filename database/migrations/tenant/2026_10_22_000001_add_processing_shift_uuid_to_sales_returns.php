<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REFUND-SHIFT-H1 (owner decision H1, 22 Oct 2026) — the STABLE identity of the shift that PAID a refund.
 *
 * "The shift that actually pays the refund owns the refund cash movement." REFUND-SHIFT-1 gave a return the numeric
 * shift_id / terminal_id of the drawer that paid it — fine inside ONE database, meaningless across two: an Edge
 * appliance's local shift ids never equal the Cloud's. From now every return also remembers the paying shift's
 * immutable ULID (shifts.shift_uuid), on the Online row (the shift that paid it) and on the appliance's local row
 * (its local processing shift), and the return event carries THAT identity to the Cloud — never a local integer.
 *
 *   processing_shift_uuid   CHAR(26) NULL — the paying shift's shift_uuid; on a Cloud row ingested from an Edge
 *                           return event it is the appliance's local processing shift, kept even while the Cloud
 *                           holds no shifts row for it (a future Edge-shift mirror resolves it by this identity).
 *
 * Additive, NULL on every existing return. Indexed (lookup by identity). No FK: shifts rows of an appliance never
 * exist on the Cloud today. MySQL: ADD COLUMN ALGORITHM=INSTANT, the index INPLACE/LOCK=NONE, lock_wait_timeout 10.
 * Shipped before the code that writes it (schema first).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_returns') || Schema::hasColumn('sales_returns', 'processing_shift_uuid')) {
            return;
        }
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET SESSION lock_wait_timeout = 10');
            DB::statement('ALTER TABLE `sales_returns` ADD COLUMN `processing_shift_uuid` CHAR(26) NULL, ALGORITHM=INSTANT');
            DB::statement('ALTER TABLE `sales_returns` ADD INDEX `sales_returns_processing_shift_uuid_index` (`processing_shift_uuid`), ALGORITHM=INPLACE, LOCK=NONE');

            return;
        }
        Schema::table('sales_returns', function (Blueprint $t) {
            $t->char('processing_shift_uuid', 26)->nullable()->index();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_returns', 'processing_shift_uuid')) {
            Schema::table('sales_returns', function (Blueprint $t) {
                $t->dropIndex('sales_returns_processing_shift_uuid_index');
                $t->dropColumn('processing_shift_uuid');
            });
        }
    }
};
