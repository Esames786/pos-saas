<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HIDE-AMOUNTS-1 (a) — a branch can count its drawer blind.
 *
 * With this on, the counter is shown `*****` where the expected cash, the cash/card/bank breakup
 * and the sales figure used to be, and the Counted box arrives EMPTY instead of pre-filled with
 * the amount it is meant to verify. The operator has to count the drawer and type what is
 * actually there.
 *
 * DEFAULT IS OFF, deliberately. Every tenant keeps exactly the screen it has today; hiding is
 * something an owner switches on for a branch, never something a deploy does to them. Same
 * reasoning as `sales_return_approval_mode` (2026_09_01_000001): a new control defaults to
 * today's behaviour, not to the stricter one.
 *
 * Hiding is TWO switches — this flag AND the `tenant.shifts.view-amounts` permission
 * (2026_09_01_000004). Both must move before anything changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasColumn('branches', 'hide_amounts_from_operators')) {
            return;
        }

        Schema::connection('tenant')->table('branches', function (Blueprint $table) {
            $table->boolean('hide_amounts_from_operators')->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasColumn('branches', 'hide_amounts_from_operators')) {
            return;
        }

        Schema::connection('tenant')->table('branches', function (Blueprint $table) {
            $table->dropColumn('hide_amounts_from_operators');
        });
    }
};
