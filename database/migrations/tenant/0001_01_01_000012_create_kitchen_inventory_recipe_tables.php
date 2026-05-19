<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'tenant';

    public function up(): void
    {
        // Patch products table with kitchen/inventory profile fields
        Schema::connection('tenant')->table('products', function (Blueprint $table) {
            if (!Schema::connection('tenant')->hasColumn('products', 'item_kind')) {
                $table->enum('item_kind', ['ingredient', 'finished_good', 'both'])->default('finished_good')->after('product_type');
            }
            if (!Schema::connection('tenant')->hasColumn('products', 'inventory_consumption_method')) {
                $table->enum('inventory_consumption_method', ['stock_item', 'recipe', 'none'])->default('stock_item')->after('item_kind');
            }
            if (!Schema::connection('tenant')->hasColumn('products', 'is_perishable')) {
                $table->boolean('is_perishable')->default(false)->after('inventory_consumption_method');
            }
            if (!Schema::connection('tenant')->hasColumn('products', 'storage_type')) {
                $table->string('storage_type', 50)->nullable()->after('is_perishable');
            }
            if (!Schema::connection('tenant')->hasColumn('products', 'shelf_life_days')) {
                $table->unsignedSmallInteger('shelf_life_days')->nullable()->after('storage_type');
            }
            if (!Schema::connection('tenant')->hasColumn('products', 'default_wastage_percent')) {
                $table->decimal('default_wastage_percent', 5, 2)->default(0)->after('shelf_life_days');
            }
        });

        // Unit conversions
        if (!Schema::connection('tenant')->hasTable('unit_conversions')) {
            Schema::connection('tenant')->create('unit_conversions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('from_unit_id');
                $table->unsignedBigInteger('to_unit_id');
                $table->decimal('factor', 20, 8);
                $table->timestamps();

                $table->unique(['from_unit_id', 'to_unit_id']);
                $table->foreign('from_unit_id')->references('id')->on('units')->cascadeOnDelete();
                $table->foreign('to_unit_id')->references('id')->on('units')->cascadeOnDelete();
            });
        }

        // Recipes (Bill of Materials)
        if (!Schema::connection('tenant')->hasTable('recipes')) {
            Schema::connection('tenant')->create('recipes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id');
                $table->string('name', 190);
                $table->decimal('yield_quantity', 12, 4)->default(1);
                $table->unsignedBigInteger('yield_unit_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->foreign('yield_unit_id')->references('id')->on('units')->nullOnDelete();
            });
        }

        // Recipe ingredients
        if (!Schema::connection('tenant')->hasTable('recipe_ingredients')) {
            Schema::connection('tenant')->create('recipe_ingredients', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('recipe_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('product_variant_id')->nullable();
                $table->decimal('quantity', 12, 4);
                $table->unsignedBigInteger('unit_id')->nullable();
                $table->decimal('cost_override', 12, 4)->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->foreign('recipe_id')->references('id')->on('recipes')->cascadeOnDelete();
                $table->foreign('product_id')->references('id')->on('products');
                $table->foreign('product_variant_id')->references('id')->on('product_variants')->nullOnDelete();
                $table->foreign('unit_id')->references('id')->on('units')->nullOnDelete();
            });
        }

        // Recipe consumptions — ingredient deductions linked to paid sales
        if (!Schema::connection('tenant')->hasTable('recipe_consumptions')) {
            Schema::connection('tenant')->create('recipe_consumptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('recipe_id');
                $table->unsignedBigInteger('sales_order_id');
                $table->unsignedBigInteger('sales_order_line_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('product_variant_id')->nullable();
                $table->decimal('quantity_consumed', 12, 4);
                $table->unsignedBigInteger('unit_id')->nullable();
                $table->timestamp('consumed_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->foreign('recipe_id')->references('id')->on('recipes');
                $table->foreign('sales_order_id')->references('id')->on('sales_orders')->cascadeOnDelete();
                $table->foreign('sales_order_line_id')->references('id')->on('sales_order_lines')->cascadeOnDelete();
                $table->foreign('product_id')->references('id')->on('products');
                $table->foreign('product_variant_id')->references('id')->on('product_variants')->nullOnDelete();
                $table->foreign('unit_id')->references('id')->on('units')->nullOnDelete();
            });
        }

        // Kitchen productions
        if (!Schema::connection('tenant')->hasTable('kitchen_productions')) {
            Schema::connection('tenant')->create('kitchen_productions', function (Blueprint $table) {
                $table->id();
                $table->string('production_no', 50)->unique();
                $table->unsignedBigInteger('branch_id');
                $table->unsignedBigInteger('recipe_id');
                $table->decimal('quantity_produced', 12, 4);
                $table->unsignedBigInteger('yield_unit_id')->nullable();
                $table->date('production_date');
                $table->enum('status', ['planned', 'in_progress', 'completed', 'cancelled'])->default('planned');
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('produced_by_user_id')->nullable();
                $table->timestamps();

                $table->foreign('branch_id')->references('id')->on('branches');
                $table->foreign('recipe_id')->references('id')->on('recipes');
                $table->foreign('yield_unit_id')->references('id')->on('units')->nullOnDelete();
                $table->foreign('produced_by_user_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        // Kitchen production ingredients
        if (!Schema::connection('tenant')->hasTable('kitchen_production_ingredients')) {
            Schema::connection('tenant')->create('kitchen_production_ingredients', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('kitchen_production_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('product_variant_id')->nullable();
                $table->decimal('quantity_required', 12, 4);
                $table->decimal('quantity_used', 12, 4)->default(0);
                $table->unsignedBigInteger('unit_id')->nullable();
                $table->timestamps();

                $table->foreign('kitchen_production_id')->references('id')->on('kitchen_productions')->cascadeOnDelete();
                $table->foreign('product_id')->references('id')->on('products');
                $table->foreign('product_variant_id')->references('id')->on('product_variants')->nullOnDelete();
                $table->foreign('unit_id')->references('id')->on('units')->nullOnDelete();
            });
        }

        // Kitchen wastages
        if (!Schema::connection('tenant')->hasTable('kitchen_wastages')) {
            Schema::connection('tenant')->create('kitchen_wastages', function (Blueprint $table) {
                $table->id();
                $table->string('wastage_no', 50)->unique();
                $table->unsignedBigInteger('branch_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('product_variant_id')->nullable();
                $table->decimal('quantity', 12, 4);
                $table->unsignedBigInteger('unit_id')->nullable();
                $table->string('reason', 255)->nullable();
                $table->date('wastage_date');
                $table->unsignedBigInteger('recorded_by_user_id')->nullable();
                $table->timestamps();

                $table->foreign('branch_id')->references('id')->on('branches');
                $table->foreign('product_id')->references('id')->on('products');
                $table->foreign('product_variant_id')->references('id')->on('product_variants')->nullOnDelete();
                $table->foreign('unit_id')->references('id')->on('units')->nullOnDelete();
                $table->foreign('recorded_by_user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('kitchen_wastages');
        Schema::connection('tenant')->dropIfExists('kitchen_production_ingredients');
        Schema::connection('tenant')->dropIfExists('kitchen_productions');
        Schema::connection('tenant')->dropIfExists('recipe_consumptions');
        Schema::connection('tenant')->dropIfExists('recipe_ingredients');
        Schema::connection('tenant')->dropIfExists('recipes');
        Schema::connection('tenant')->dropIfExists('unit_conversions');
    }
};
