<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KITCHEN-RECIPE-ORDER-TYPE-1 — per recipe-line order-type applicability.
 *
 * recipe_ingredients.applicable_order_types: JSON list of POS order types this line
 * applies to (e.g. ["takeaway","delivery"] for packing). NULL/empty/["all"] = all
 * order types. Config + report filtering only — no stock-consumption change here.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $sm = Schema::connection('tenant');

        $sm->table('recipe_ingredients', function (Blueprint $t) use ($sm) {
            if (! $sm->hasColumn('recipe_ingredients', 'applicable_order_types')) {
                $t->json('applicable_order_types')->nullable()->after('line_section');
            }
        });
    }

    public function down(): void
    {
        $sm = Schema::connection('tenant');

        $sm->table('recipe_ingredients', function (Blueprint $t) use ($sm) {
            if ($sm->hasColumn('recipe_ingredients', 'applicable_order_types')) {
                $t->dropColumn('applicable_order_types');
            }
        });
    }
};
