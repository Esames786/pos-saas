<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KITCHEN-RECIPE-COST-1 — Technosys-style recipe cost report fields.
 *
 * recipes:            header doc/revision/review fields + overhead_percent.
 * recipe_ingredients: line_section so the report can group Food Cost / Packing Material.
 * products:           purchase_unit_id + purchase_pack_size for correct per-line costing
 *                     (Price/Unit = Cost Price ÷ pack size). All additive + POS-safe.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $sm = Schema::connection('tenant');

        $sm->table('recipes', function (Blueprint $t) use ($sm) {
            if (! $sm->hasColumn('recipes', 'doc_no'))         $t->string('doc_no')->nullable()->after('name');
            if (! $sm->hasColumn('recipes', 'recipe_no'))      $t->string('recipe_no')->nullable()->after('doc_no');
            if (! $sm->hasColumn('recipes', 'revision_no'))    $t->unsignedInteger('revision_no')->default(1)->after('recipe_no');
            if (! $sm->hasColumn('recipes', 'review_date'))    $t->date('review_date')->nullable()->after('revision_no');
            if (! $sm->hasColumn('recipes', 'overhead_percent')) $t->decimal('overhead_percent', 8, 4)->default(0)->after('review_date');
        });

        $sm->table('recipe_ingredients', function (Blueprint $t) use ($sm) {
            if (! $sm->hasColumn('recipe_ingredients', 'line_section')) {
                $t->string('line_section', 30)->default('food_cost')->after('cost_override');
                $t->index('line_section', 'recipe_ingredients_section_idx');
            }
        });

        $sm->table('products', function (Blueprint $t) use ($sm) {
            if (! $sm->hasColumn('products', 'purchase_unit_id'))   $t->unsignedBigInteger('purchase_unit_id')->nullable()->after('unit_id');
            if (! $sm->hasColumn('products', 'purchase_pack_size')) $t->decimal('purchase_pack_size', 18, 4)->nullable()->after('purchase_unit_id');
        });

        // Safe backfill: default the purchase unit to the existing stock unit. Pack size is
        // left NULL (treated as 1 by the report) so the user configures real pack sizes.
        if ($sm->hasColumn('products', 'purchase_unit_id')) {
            DB::connection('tenant')->statement('UPDATE products SET purchase_unit_id = unit_id WHERE purchase_unit_id IS NULL');
        }
    }

    public function down(): void
    {
        $sm = Schema::connection('tenant');

        $sm->table('products', function (Blueprint $t) use ($sm) {
            foreach (['purchase_pack_size', 'purchase_unit_id'] as $c) {
                if ($sm->hasColumn('products', $c)) $t->dropColumn($c);
            }
        });
        $sm->table('recipe_ingredients', function (Blueprint $t) use ($sm) {
            if ($sm->hasColumn('recipe_ingredients', 'line_section')) {
                $t->dropIndex('recipe_ingredients_section_idx');
                $t->dropColumn('line_section');
            }
        });
        $sm->table('recipes', function (Blueprint $t) use ($sm) {
            foreach (['overhead_percent', 'review_date', 'revision_no', 'recipe_no', 'doc_no'] as $c) {
                if ($sm->hasColumn('recipes', $c)) $t->dropColumn($c);
            }
        });
    }
};
