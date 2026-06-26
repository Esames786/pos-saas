<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRODUCT-BOUNDARY-2 — logical product role / visibility separation.
 *
 * products.id stays the single inventory identity (no separate manufacturing table).
 * These flags let manufacturing/internal products stay visible across the portal but
 * be hidden from the POS unless explicitly allowed.
 *
 * Defaults are POS-safe: every existing product stays sale_item + POS-visible, so the
 * POS is never broken. `is_sellable` / `is_purchasable` already exist and are reused.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $sm = Schema::connection('tenant');

        $sm->table('products', function (Blueprint $table) use ($sm) {
            if (!$sm->hasColumn('products', 'product_kind')) {
                $table->string('product_kind', 30)->default('sale_item')->after('product_type');
                $table->index('product_kind', 'products_kind_idx');
            }
            if (!$sm->hasColumn('products', 'is_pos_visible')) {
                $table->boolean('is_pos_visible')->default(true)->after('is_sellable');
                $table->index('is_pos_visible', 'products_pos_visible_idx');
            }
            if (!$sm->hasColumn('products', 'can_be_bom_component')) {
                $table->boolean('can_be_bom_component')->default(false)->after('is_pos_visible');
                $table->index('can_be_bom_component', 'products_bom_comp_idx');
            }
            if (!$sm->hasColumn('products', 'can_be_bom_output')) {
                $table->boolean('can_be_bom_output')->default(false)->after('can_be_bom_component');
                $table->index('can_be_bom_output', 'products_bom_output_idx');
            }
            if (!$sm->hasColumn('products', 'is_manufactured_finished_good')) {
                $table->boolean('is_manufactured_finished_good')->default(false)->after('can_be_bom_output');
                $table->index('is_manufactured_finished_good', 'products_mfg_fg_idx');
            }
        });

        // Safe backfill: classify EXISTING kitchen/manufacturing ingredients as raw materials.
        // Scoped to products that are ALREADY is_sellable=false, so this hides nothing that
        // was visible in the POS — it only labels what was already excluded.
        if ($sm->hasColumn('products', 'item_kind')) {
            DB::connection('tenant')->table('products')
                ->where('item_kind', 'ingredient')
                ->where('is_sellable', false)
                ->update([
                    'product_kind'         => 'raw_material',
                    'is_pos_visible'       => false,
                    'can_be_bom_component' => true,
                ]);
        }

        // Keep existing manufacturing dropdowns populated: any product already used as a
        // BOM output / production target → BOM output; already used as a BOM component →
        // BOM component. Additive only — never hides anything.
        $db = DB::connection('tenant');
        if ($sm->hasTable('manufacturing_boms')) {
            $db->statement('UPDATE products p SET can_be_bom_output = 1 WHERE EXISTS (SELECT 1 FROM manufacturing_boms b WHERE b.finished_product_id = p.id)');
        }
        if ($sm->hasTable('manufacturing_bom_lines')) {
            $db->statement('UPDATE products p SET can_be_bom_component = 1 WHERE EXISTS (SELECT 1 FROM manufacturing_bom_lines l WHERE l.component_product_id = p.id)');
        }
        if ($sm->hasTable('production_orders')) {
            $db->statement('UPDATE products p SET can_be_bom_output = 1 WHERE EXISTS (SELECT 1 FROM production_orders o WHERE o.product_id = p.id)');
        }
    }

    public function down(): void
    {
        $sm = Schema::connection('tenant');

        $sm->table('products', function (Blueprint $table) use ($sm) {
            foreach ([
                'is_manufactured_finished_good',
                'can_be_bom_output',
                'can_be_bom_component',
                'is_pos_visible',
                'product_kind',
            ] as $col) {
                if ($sm->hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
