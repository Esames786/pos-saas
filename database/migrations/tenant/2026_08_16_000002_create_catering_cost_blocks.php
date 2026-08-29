<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KASHIF-CATERING-COST-BLOCKS-1 — a dish price built from its parts.
 *
 * Until now a dish carried one typed number, default_catering_rate, and the
 * Material Rate Book moved only the internal cost. Kashif wants the opposite:
 * the price is the sum of named blocks, so when chicken rises the dish rises.
 *
 *   Chicken Karahi, per KG      Biryani, per KG
 *     chicken  200                chicken  200   · 0.5 KG per KG
 *     making   500                rice     120   · 0.5 KG per KG
 *     ───────────                 making   400
 *     rate     700                ───────────
 *                                 rate     720
 *
 * TWO numbers per material block, and they are genuinely independent:
 *
 *   rate               what the CUSTOMER pays, per unit of the DISH
 *   quantity_per_unit  how much MATERIAL one unit of dish consumes
 *
 * 20 KG of karahi charges 20 × 200 for chicken while drawing 20 × 0.5 = 10 KG
 * from the store. Collapsing them into one number would make either the bill or
 * the kitchen sheet wrong.
 *
 * A charge block (making, packing) has a rate and no material: it is money, not
 * stock. It may be per_unit — rate × quantity — or lump_sum, charged once
 * however large the order. Kashif confirmed both are needed.
 *
 * Purely additive. Nothing existing reads these tables, and a dish with no
 * blocks behaves exactly as it does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('catering_product_cost_blocks')) {
            Schema::connection('tenant')->create('catering_product_cost_blocks', function (Blueprint $table) {
                $table->id();
                $table->char('block_uuid', 26)->nullable()->unique();

                // The DISH this block belongs to.
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

                $table->string('label', 120);
                $table->string('block_type', 20); // material | charge
                $table->unsignedSmallInteger('sort_order')->default(0);

                // Material blocks only. Restricted on delete: a material still
                // priced into a live dish must not vanish underneath it.
                $table->foreignId('material_product_id')->nullable()
                    ->constrained('products')->restrictOnDelete();

                // How much material one unit of the dish consumes. 0.5 means a
                // 10 KG biryani draws 5 KG. Null for charge blocks.
                $table->decimal('quantity_per_unit', 12, 4)->nullable();
                $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();

                // What the customer pays for this block, per unit of the dish
                // (or once, when the basis is lump_sum).
                $table->decimal('rate', 14, 4)->default(0);
                $table->string('charge_basis', 20)->default('per_unit'); // per_unit | lump_sum

                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['product_id', 'sort_order'], 'cpcb_product_order_idx');
                $table->index('material_product_id', 'cpcb_material_idx');
            });
        }

        // Which method prices this dish. Existing rows default to 'recipe',
        // which is exactly what they do today — nothing changes until a dish is
        // deliberately switched.
        if (Schema::connection('tenant')->hasTable('catering_product_profiles')
            && ! Schema::connection('tenant')->hasColumn('catering_product_profiles', 'costing_mode')) {
            Schema::connection('tenant')->table('catering_product_profiles', function (Blueprint $table) {
                $table->string('costing_mode', 20)->default('recipe')->after('pricing_mode');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('catering_product_profiles', 'costing_mode')) {
            Schema::connection('tenant')->table('catering_product_profiles', function (Blueprint $table) {
                $table->dropColumn('costing_mode');
            });
        }

        Schema::connection('tenant')->dropIfExists('catering_product_cost_blocks');
    }
};
