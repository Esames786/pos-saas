<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KITCHEN-RECIPE-CONSUME-ORDER-TYPE-1 — audit columns on recipe_consumptions so each
 * deduction records which recipe line + POS order type + section caused it. All nullable
 * (legacy rows stay null) and POS/finance-safe — purely informational.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $sm = Schema::connection('tenant');

        $sm->table('recipe_consumptions', function (Blueprint $t) use ($sm) {
            if (! $sm->hasColumn('recipe_consumptions', 'recipe_ingredient_id')) {
                $t->unsignedBigInteger('recipe_ingredient_id')->nullable()->after('recipe_id');
            }
            if (! $sm->hasColumn('recipe_consumptions', 'order_type')) {
                $t->string('order_type', 30)->nullable()->after('quantity_consumed');
            }
            if (! $sm->hasColumn('recipe_consumptions', 'line_section')) {
                $t->string('line_section', 30)->nullable()->after('order_type');
            }
        });
    }

    public function down(): void
    {
        $sm = Schema::connection('tenant');

        $sm->table('recipe_consumptions', function (Blueprint $t) use ($sm) {
            foreach (['line_section', 'order_type', 'recipe_ingredient_id'] as $c) {
                if ($sm->hasColumn('recipe_consumptions', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
