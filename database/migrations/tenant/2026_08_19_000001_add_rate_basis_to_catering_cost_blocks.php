<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KASHIF-CATERING-MATERIAL-RATE-BASIS-1 — what a material's rate is a rate OF.
 *
 * Until now a material block's rate meant "rupees per unit of the finished
 * dish", so chicken in a biryani was priced at what it adds to a kilo of
 * biryani. A caterer does not think that way. They think "chicken costs the
 * customer 100 a kilo", and the dish needs two and a half kilos of it.
 *
 * Both are legitimate; they are simply different rates, differing by the
 * consumption ratio:
 *
 *   per_dish_unit      amount = dish qty                  x rate
 *   per_material_unit  amount = dish qty x ratio (= material needed) x rate
 *
 * On a block with ratio 0.5 the same stored number produces DOUBLE under one
 * reading and HALF under the other, so reinterpreting the column in place would
 * silently reprice every dish that has ever been quoted. This is why the basis
 * is recorded rather than assumed.
 *
 * Existing rows default to per_dish_unit — the meaning they were authored under
 * and the meaning the migration comment, the model, the contract document and
 * the on-screen help all stated at the time. Nothing already priced moves.
 * New material blocks are authored per_material_unit, which is the model the
 * business actually uses.
 *
 * Additive and reversible. A charge block has no material, so the column simply
 * does not apply to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('catering_product_cost_blocks')) {
            return;
        }

        if (! Schema::connection('tenant')->hasColumn('catering_product_cost_blocks', 'rate_basis')) {
            Schema::connection('tenant')->table('catering_product_cost_blocks', function (Blueprint $table) {
                // Deliberately NOT nullable with a legacy default: every existing
                // row genuinely was per_dish_unit, and saying so explicitly is
                // what keeps old quotations reproducible.
                $table->string('rate_basis', 20)
                    ->default('per_dish_unit')
                    ->after('charge_basis');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('catering_product_cost_blocks', 'rate_basis')) {
            Schema::connection('tenant')->table('catering_product_cost_blocks', function (Blueprint $table) {
                $table->dropColumn('rate_basis');
            });
        }
    }
};
