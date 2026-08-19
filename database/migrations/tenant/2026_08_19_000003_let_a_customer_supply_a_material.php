<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KASHIF-CATERING-CUSTOMER-SUPPLIED-1 — the customer brings their own chicken.
 *
 * A common arrangement: the family provides the meat, the caterer cooks it. Two
 * things are true at once and the system has to hold both.
 *
 *   THE KITCHEN STILL NEEDS 5 KG. The dish has not changed and neither has the
 *   cooking. Zeroing the requirement would be a lie about the food.
 *
 *   THE BUSINESS ISSUES NONE OF IT. Its own store hands over nothing, and the
 *   customer is charged nothing for that material — though making, packing and
 *   labour are charged exactly as before.
 *
 * So this is a flag, NOT a quantity edit. event_material_qty keeps saying what
 * the kitchen needs; the flag says who brings it. Collapsing the two would lose
 * the requirement the moment somebody ticked the box, and the kitchen sheet
 * would start asking for nothing.
 *
 *   physical requirement   = event_material_qty          (unchanged)
 *   our stock requirement  = supplied ? 0 : event_material_qty
 *   customer contribution  = supplied ? 0 : normal
 *
 * Deliberately binary for now: the whole material, or none of it. A partial
 * supply needs allocation rules nobody has agreed, and inventing them here
 * would make every historical snapshot ambiguous. The flag is shaped so a
 * future supplied-quantity column can sit beside it without changing what these
 * rows mean.
 *
 * Additive, and false everywhere, so no existing quotation moves.
 */
return new class extends Migration
{
    private const TABLE = 'catering_estimate_line_cost_blocks';

    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::connection('tenant')->hasColumn(self::TABLE, 'is_customer_supplied')) {
            Schema::connection('tenant')->table(self::TABLE, function (Blueprint $table) {
                $table->boolean('is_customer_supplied')->default(false)->after('is_overridden');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn(self::TABLE, 'is_customer_supplied')) {
            Schema::connection('tenant')->table(self::TABLE, function (Blueprint $table) {
                $table->dropColumn('is_customer_supplied');
            });
        }
    }
};
